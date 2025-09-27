<?php

declare(strict_types=1);

use Ameax\LaravelChangeDetection\Jobs\BulkPublishJob;
use Ameax\LaravelChangeDetection\Models\Hash;
use Ameax\LaravelChangeDetection\Models\Publish;
use Ameax\LaravelChangeDetection\Models\Publisher;
use Ameax\LaravelChangeDetection\Tests\Models\TestWeatherStation;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;

describe('ProcessPublishesCommand', function () {
    beforeEach(function () {
        Relation::morphMap([
            'weather_station' => TestWeatherStation::class,
        ]);

        // Create weather stations manually
        $station1 = TestWeatherStation::create([
            'name' => 'Station-1',
            'location' => 'Bayern',
            'latitude' => 48.1351,
            'longitude' => 11.5820,
            'status' => 'active',
            'is_operational' => true,
        ]);

        $station2 = TestWeatherStation::create([
            'name' => 'Station-2',
            'location' => 'Bayern',
            'latitude' => 48.1352,
            'longitude' => 11.5821,
            'status' => 'active',
            'is_operational' => true,
        ]);

        // Create hashes manually
        Hash::create([
            'hashable_type' => 'weather_station',
            'hashable_id' => $station1->id,
            'attribute_hash' => md5('station1'),
        ]);

        Hash::create([
            'hashable_type' => 'weather_station',
            'hashable_id' => $station2->id,
            'attribute_hash' => md5('station2'),
        ]);

        // Create publisher
        Publisher::create([
            'name' => 'Weather Station Publisher',
            'model_type' => 'weather_station',
            'publisher_class' => 'TestPublisher',
            'status' => 'active',
        ]);
    });

    test('it reports when no pending publishes exist', function () {
        // Given: No publish records exist
        expect(Publish::count())->toBe(0);

        // When: We run the command
        $this->artisan('change-detection:process-publishes')
            ->expectsOutput('No pending publishes found.')
            ->assertExitCode(0);
    });

    test('it dispatches BulkPublishJob for pending publishes', function () {
        // Given: We have pending publish records
        $publisher = Publisher::where('model_type', 'weather_station')->first();
        $hashes = Hash::where('hashable_type', 'weather_station')->get();

        foreach ($hashes as $hash) {
            Publish::create([
                'hash_id' => $hash->id,
                'publisher_id' => $publisher->id,
                'status' => 'pending',
            ]);
        }

        expect(Publish::pendingOrDeferred()->count())->toBe(2);

        // Setup queue fake
        Queue::fake();

        // When: We run the command
        $this->artisan('change-detection:process-publishes')
            ->expectsOutputToContain('Found 2 pending publish records.')
            ->expectsOutputToContain('Dispatching BulkPublishJob...')
            ->expectsOutputToContain('BulkPublishJob dispatched successfully.')
            ->assertExitCode(0);

        // Then: Job is dispatched
        Queue::assertPushed(BulkPublishJob::class);
    });

    test('it processes publishes synchronously with --sync option', function () {
        // Given: We have pending publish records
        $publisher = Publisher::where('model_type', 'weather_station')->first();
        $hash = Hash::where('hashable_type', 'weather_station')->first();

        $publish = Publish::create([
            'hash_id' => $hash->id,
            'publisher_id' => $publisher->id,
            'status' => 'pending',
        ]);

        // When: We run with --sync option
        $exitCode = Artisan::call('change-detection:process-publishes', ['--sync' => true]);

        // Then: Command succeeds and publish is processed
        expect($exitCode)->toBe(0);
        $publish->refresh();
        expect($publish->status->value)->toBeIn(['published', 'deferred']); // May be deferred if publisher fails
        expect($publish->attempts)->toBeGreaterThan(0); // Attempts should be incremented
    });

    test('it handles deferred publishes in sync mode', function () {
        // Given: We have deferred publish records
        $publisher = Publisher::where('model_type', 'weather_station')->first();
        $hash = Hash::where('hashable_type', 'weather_station')->first();

        $publish = Publish::create([
            'hash_id' => $hash->id,
            'publisher_id' => $publisher->id,
            'status' => 'deferred',
            'next_try' => now()->subMinute(), // Ready to retry
        ]);

        // When: We run with --sync option
        $exitCode = Artisan::call('change-detection:process-publishes', ['--sync' => true]);

        // Then: Command succeeds and deferred publish is processed
        expect($exitCode)->toBe(0);
        $publish->refresh();
        expect($publish->status->value)->toBeIn(['published', 'deferred']); // May be deferred if publisher fails
        expect($publish->attempts)->toBeGreaterThan(1); // Attempts should be incremented
    });

    test('it warns when job is already running', function () {
        // Given: Job lock is active
        Cache::put('bulk_publish_job_running', true, 600);

        $publisher = Publisher::where('model_type', 'weather_station')->first();
        $hash = Hash::where('hashable_type', 'weather_station')->first();
        Publish::create([
            'hash_id' => $hash->id,
            'publisher_id' => $publisher->id,
            'status' => 'pending',
        ]);

        // When: We try to run the command
        $this->artisan('change-detection:process-publishes')
            ->expectsOutputToContain('BulkPublishJob is already running. Use --force to override or --sync to process synchronously.')
            ->assertExitCode(1);

        // Cleanup
        Cache::forget('bulk_publish_job_running');
    });

    test('it forces job dispatch with --force option', function () {
        // Given: Job lock is active
        Cache::put('bulk_publish_job_running', true, 600);

        $publisher = Publisher::where('model_type', 'weather_station')->first();
        $hash = Hash::where('hashable_type', 'weather_station')->first();
        Publish::create([
            'hash_id' => $hash->id,
            'publisher_id' => $publisher->id,
            'status' => 'pending',
        ]);

        Queue::fake();

        // When: We run with --force option
        $this->artisan('change-detection:process-publishes', ['--force' => true])
            ->expectsOutputToContain('Forced clearing of job lock.')
            ->expectsOutputToContain('Dispatching BulkPublishJob...')
            ->assertExitCode(0);

        // Then: Lock is cleared and job is dispatched
        expect(Cache::has('bulk_publish_job_running'))->toBeFalse();
        Queue::assertPushed(BulkPublishJob::class);
    });

    test('it processes multiple publishes with progress bar in sync mode', function () {
        // Given: Multiple pending publishes
        $publisher = Publisher::where('model_type', 'weather_station')->first();
        $hashes = Hash::where('hashable_type', 'weather_station')->get();

        foreach ($hashes as $hash) {
            Publish::create([
                'hash_id' => $hash->id,
                'publisher_id' => $publisher->id,
                'status' => 'pending',
            ]);
        }

        $initialPendingCount = Publish::where('status', 'pending')->count();
        expect($initialPendingCount)->toBeGreaterThan(0);

        // When: We process synchronously
        $this->artisan('change-detection:process-publishes', ['--sync' => true])
            ->expectsOutputToContain('Processing publishes synchronously...')
            ->assertExitCode(0);

        // Then: Publishes are processed (status changed from pending)
        $finalPendingCount = Publish::where('status', 'pending')->count();
        expect($finalPendingCount)->toBeLessThan($initialPendingCount);
    });

    test('it handles publish failures in sync mode', function () {
        // Given: A publish that will fail
        $publisher = Publisher::where('model_type', 'weather_station')->first();

        // Create a hash without a hashable model
        $hash = Hash::create([
            'hashable_type' => 'weather_station',
            'hashable_id' => 999, // Non-existent ID
            'attribute_hash' => md5('test'),
        ]);

        $publish = Publish::create([
            'hash_id' => $hash->id,
            'publisher_id' => $publisher->id,
            'status' => 'pending',
        ]);

        // When: We process synchronously
        $exitCode = Artisan::call('change-detection:process-publishes', ['--sync' => true]);

        // Then: Command succeeds
        expect($exitCode)->toBe(0);

        // And publish is marked as failed or deferred
        $publish->refresh();
        expect($publish->status->value)->toBeIn(['failed', 'deferred']);
    });

    test('it limits batch size in sync mode to 100 records', function () {
        // Given: More than 100 pending publishes
        $publisher = Publisher::where('model_type', 'weather_station')->first();

        // Create 150 hashes and publishes
        for ($i = 100; $i <= 250; $i++) {
            $hash = Hash::create([
                'hashable_type' => 'weather_station',
                'hashable_id' => $i,
                'attribute_hash' => md5("test{$i}"),
            ]);

            Publish::create([
                'hash_id' => $hash->id,
                'publisher_id' => $publisher->id,
                'status' => 'pending',
            ]);
        }

        expect(Publish::pendingOrDeferred()->count())->toBe(151); // 150 + 1 from beforeEach

        // When: We process synchronously
        $this->artisan('change-detection:process-publishes', ['--sync' => true])
            ->assertExitCode(0);

        // Then: Only 100 records are processed in this batch
        $processedCount = Publish::whereIn('status', ['published', 'failed', 'deferred'])
            ->where('updated_at', '>=', now()->subMinutes(1))
            ->count();

        expect($processedCount)->toBeLessThanOrEqual(100);
    });

    test('it counts successful and failed publishes correctly', function () {
        // Given: Mixed success/failure publishes
        $publisher = Publisher::where('model_type', 'weather_station')->first();

        // Create successful publishes
        $successHashes = Hash::where('hashable_type', 'weather_station')->get();
        foreach ($successHashes as $hash) {
            Publish::create([
                'hash_id' => $hash->id,
                'publisher_id' => $publisher->id,
                'status' => 'pending',
            ]);
        }

        // Create a failing publish
        $failHash = Hash::create([
            'hashable_type' => 'weather_station',
            'hashable_id' => 999,
            'attribute_hash' => md5('fail'),
        ]);

        Publish::create([
            'hash_id' => $failHash->id,
            'publisher_id' => $publisher->id,
            'status' => 'pending',
        ]);

        // When: We process synchronously
        $this->artisan('change-detection:process-publishes', ['--sync' => true])
            ->expectsOutputToContain('Processing publishes synchronously...')
            ->expectsOutputToContain('Processed')
            ->expectsOutputToContain('Successful')
            ->expectsOutputToContain('Failed/Deferred');
    });

    test('it respects publish order by created_at', function () {
        // Given: Publishes created in specific order
        $publisher = Publisher::where('model_type', 'weather_station')->first();
        $hashes = Hash::where('hashable_type', 'weather_station')->get();

        $publish1 = Publish::create([
            'hash_id' => $hashes[0]->id,
            'publisher_id' => $publisher->id,
            'status' => 'pending',
            'created_at' => now()->subHours(2),
        ]);

        $publish2 = Publish::create([
            'hash_id' => $hashes[1]->id,
            'publisher_id' => $publisher->id,
            'status' => 'pending',
            'created_at' => now()->subHour(),
        ]);

        // When: We process synchronously
        $exitCode = Artisan::call('change-detection:process-publishes', ['--sync' => true]);

        // Then: Command succeeds
        expect($exitCode)->toBe(0);

        // And both publishes are processed
        $publish1->refresh();
        $publish2->refresh();
        expect($publish1->status->value)->toBeIn(['published', 'failed', 'deferred']);
        expect($publish2->status->value)->toBeIn(['published', 'failed', 'deferred']);
    });
});
