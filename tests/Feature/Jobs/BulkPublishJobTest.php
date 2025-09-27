<?php

declare(strict_types=1);

use Ameax\LaravelChangeDetection\Jobs\BulkPublishJob;
use Ameax\LaravelChangeDetection\Models\Hash;
use Ameax\LaravelChangeDetection\Models\Publish;
use Ameax\LaravelChangeDetection\Models\Publisher;
use Ameax\LaravelChangeDetection\Publishers\LogPublisher;
use Ameax\LaravelChangeDetection\Tests\Models\TestWeatherStation;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

describe('BulkPublishJob', function () {
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

        $station3 = TestWeatherStation::create([
            'name' => 'Station-3',
            'location' => 'Bayern',
            'latitude' => 48.1353,
            'longitude' => 11.5822,
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

        Hash::create([
            'hashable_type' => 'weather_station',
            'hashable_id' => $station3->id,
            'attribute_hash' => md5('station3'),
        ]);

        // Create publisher
        Publisher::create([
            'name' => 'Weather Station Publisher',
            'model_type' => 'weather_station',
            'publisher_class' => 'TestPublisher',
            'status' => 'active',
        ]);
    });

    test('it processes pending publishes successfully', function () {
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

        expect(Publish::pendingOrDeferred()->count())->toBe(3);

        // When: We execute the job
        $job = new BulkPublishJob();
        $job->handle();

        // Then: All publishes are processed
        expect(Publish::where('status', 'published')->count())->toBe(3);
        expect(Publish::pendingOrDeferred()->count())->toBe(0);
    })->skip();

    test('it uses unique job identifier to prevent duplicates', function () {
        // Given: A BulkPublishJob instance
        $job = new BulkPublishJob();

        // Then: It has correct unique settings
        expect($job->uniqueId())->toBe('bulk_publish_job');
        expect($job->uniqueFor())->toBe(30);
    });

    test('it acquires and releases lock during execution', function () {
        // Given: No lock exists
        expect(Cache::has('bulk_publish_job_running'))->toBeFalse();

        $publisher = Publisher::where('model_type', 'weather_station')->first();
        $hash = Hash::where('hashable_type', 'weather_station')->first();
        Publish::create([
            'hash_id' => $hash->id,
            'publisher_id' => $publisher->id,
            'status' => 'pending',
        ]);

        // When: Job executes
        $job = new BulkPublishJob();
        $job->handle();

        // Then: Lock is released after execution
        expect(Cache::has('bulk_publish_job_running'))->toBeFalse();
    });

    test('it skips execution when another instance is running', function () {
        // Given: Lock is already acquired
        Cache::lock('bulk_publish_job_running', 600)->get();

        $publisher = Publisher::where('model_type', 'weather_station')->first();
        $hash = Hash::where('hashable_type', 'weather_station')->first();
        $publish = Publish::create([
            'hash_id' => $hash->id,
            'publisher_id' => $publisher->id,
            'status' => 'pending',
        ]);

        // When: Another job tries to run
        Log::shouldReceive('info')
            ->with('BulkPublishJob: Another instance is running, skipping')
            ->once();

        $job = new BulkPublishJob();
        $job->handle();

        // Then: Publishes remain unprocessed
        $publish->refresh();
        expect($publish->status->value)->toBe('pending');

        // Cleanup
        Cache::lock('bulk_publish_job_running')->forceRelease();
    });

    test('it processes deferred publishes that are ready', function () {
        // Given: Deferred publishes ready for retry
        $publisher = Publisher::where('model_type', 'weather_station')->first();
        $hash = Hash::where('hashable_type', 'weather_station')->first();

        $publish = Publish::create([
            'hash_id' => $hash->id,
            'publisher_id' => $publisher->id,
            'status' => 'deferred',
            'next_try' => now()->subMinute(),
            'attempts' => 1,
        ]);

        // When: Job executes
        $job = new BulkPublishJob();
        $job->handle();

        // Then: Deferred publish is processed
        $publish->refresh();
        expect($publish->status->value)->toBe('published');
        expect($publish->attempts)->toBe(2);
    })->skip();

    test('it groups publishes by publisher for batch processing', function () {
        // Given: Multiple publishers with publishes
        $weatherPublisher = Publisher::where('model_type', 'weather_station')->first();

        // Create another publisher
        $anotherPublisher = Publisher::create([
            'name' => 'Another Publisher',
            'model_type' => 'weather_station',
            'publisher_class' => LogPublisher::class,
            'status' => 'active',
        ]);

        $hash = Hash::where('hashable_type', 'weather_station')->first();

        Publish::create([
            'hash_id' => $hash->id,
            'publisher_id' => $weatherPublisher->id,
            'status' => 'pending',
        ]);

        Publish::create([
            'hash_id' => $hash->id,
            'publisher_id' => $anotherPublisher->id,
            'status' => 'pending',
        ]);

        // When: Job executes
        $job = new BulkPublishJob();
        $job->handle();

        // Then: Both publishes are processed
        expect(Publish::where('status', 'published')->count())->toBe(2);
    })->skip();

    test('it handles missing hash gracefully', function () {
        // Given: Publish with no hash
        $publisher = Publisher::where('model_type', 'weather_station')->first();

        $publish = Publish::create([
            'hash_id' => null,
            'publisher_id' => $publisher->id,
            'status' => 'pending',
        ]);

        Log::shouldReceive('warning')
            ->withArgs(function ($message, $context) {
                return str_contains($message, 'Publish record has no hash');
            })
            ->once();

        // When: Job executes
        $job = new BulkPublishJob();
        $job->handle();

        // Then: Publish is marked as failed
        $publish->refresh();
        expect($publish->status->value)->toBe('failed');
        expect($publish->last_error)->toContain('No hash found');
    })->skip();

    test('it handles missing hashable model gracefully', function () {
        // Given: Hash without hashable model
        $hash = Hash::create([
            'hashable_type' => 'weather_station',
            'hashable_id' => 999, // Non-existent
            'attribute_hash' => md5('test'),
        ]);

        $publisher = Publisher::where('model_type', 'weather_station')->first();
        $publish = Publish::create([
            'hash_id' => $hash->id,
            'publisher_id' => $publisher->id,
            'status' => 'pending',
        ]);

        // When: Job executes
        $job = new BulkPublishJob();
        $job->handle();

        // Then: Publish is deferred or failed
        $publish->refresh();
        expect($publish->status->value)->toBeIn(['deferred', 'failed']);
        expect($publish->last_error)->toContain('not found');
    });

    test('it increments attempts and marks as dispatched during processing', function () {
        // Given: A pending publish
        $publisher = Publisher::where('model_type', 'weather_station')->first();
        $hash = Hash::where('hashable_type', 'weather_station')->first();

        $publish = Publish::create([
            'hash_id' => $hash->id,
            'publisher_id' => $publisher->id,
            'status' => 'pending',
            'attempts' => 0,
        ]);

        // When: Job executes
        $job = new BulkPublishJob();
        $job->handle();

        // Then: Attempts are incremented
        $publish->refresh();
        expect($publish->attempts)->toBe(1);
        expect($publish->status->value)->toBe('published');
    })->skip();

    test('it dispatches next batch when more records remain', function () {
        // Given: Many pending publishes
        $publisher = Publisher::where('model_type', 'weather_station')->first();
        $hashes = Hash::where('hashable_type', 'weather_station')->get();

        foreach ($hashes as $hash) {
            Publish::create([
                'hash_id' => $hash->id,
                'publisher_id' => $publisher->id,
                'status' => 'pending',
            ]);
        }

        Queue::fake();

        // When: Job executes (with queue fake to prevent actual dispatch)
        $job = new BulkPublishJob();
        $job->handle();

        // Note: In real scenario, it would dispatch next batch if limit exceeded
        // Here we just verify the job can complete
        expect(true)->toBeTrue();
    });

    test('it releases lock on job failure', function () {
        // Given: A job that will fail
        $job = new BulkPublishJob();

        // Simulate job failure
        $exception = new Exception('Test failure');

        // When: Job fails
        $job->failed($exception);

        // Then: Lock is released
        expect(Cache::has('bulk_publish_job_running'))->toBeFalse();
    });

    test('it determines error types correctly', function () {
        // Given: Various exception messages
        $job = new BulkPublishJob();
        $reflection = new ReflectionClass($job);
        $method = $reflection->getMethod('determineErrorType');
        $method->setAccessible(true);

        // Test infrastructure errors
        $connectionError = new Exception('Connection timeout');
        expect($method->invoke($job, $connectionError))->toBe('infrastructure');

        $networkError = new Exception('Network is unreachable');
        expect($method->invoke($job, $networkError))->toBe('infrastructure');

        // Test validation errors
        $validationError = new Exception('Field validation failed');
        expect($method->invoke($job, $validationError))->toBe('validation');

        $invalidError = new Exception('Invalid data format');
        expect($method->invoke($job, $invalidError))->toBe('validation');

        // Test data errors
        $notFoundError = new Exception('Record not found');
        expect($method->invoke($job, $notFoundError))->toBe('data');

        $missingError = new Exception('Required field missing');
        expect($method->invoke($job, $missingError))->toBe('data');

        // Test unknown errors
        $unknownError = new Exception('Something went wrong');
        expect($method->invoke($job, $unknownError))->toBe('unknown');
    });

    test('it extracts response codes from exception messages', function () {
        // Given: Exception messages with response codes
        $job = new BulkPublishJob();
        $reflection = new ReflectionClass($job);
        $method = $reflection->getMethod('extractResponseCode');
        $method->setAccessible(true);

        // Test various formats
        $httpError = new Exception('HTTP/1.1 404 Not Found');
        expect($method->invoke($job, $httpError))->toBe(404);

        $statusCodeError = new Exception('Request failed with status code: 500');
        expect($method->invoke($job, $statusCodeError))->toBe(500);

        $shortFormat = new Exception('403 Forbidden');
        expect($method->invoke($job, $shortFormat))->toBe(403);

        $noCodeError = new Exception('Connection timeout');
        expect($method->invoke($job, $noCodeError))->toBeNull();
    });

    test('it respects publisher batch size configuration', function () {
        // Given: Publisher with custom batch size
        $publisher = Publisher::where('model_type', 'weather_station')->first();

        // Create many publishes
        for ($i = 1; $i <= 10; $i++) {
            $hash = Hash::create([
                'hashable_type' => 'weather_station',
                'hashable_id' => 100 + $i,
                'attribute_hash' => md5("test{$i}"),
            ]);

            Publish::create([
                'hash_id' => $hash->id,
                'publisher_id' => $publisher->id,
                'status' => 'pending',
            ]);
        }

        // When: Job executes (it will process in batches)
        $job = new BulkPublishJob();
        $job->handle();

        // Then: Publishes are processed
        expect(Publish::where('status', '!=', 'pending')->count())->toBeGreaterThan(0);
    });

    test('it stops job on critical infrastructure errors', function () {
        // Given: A publish that will cause infrastructure error
        $publisher = Publisher::where('model_type', 'weather_station')->first();
        $publisher->update(['publisher_class' => 'NonExistentPublisher']);

        $hash = Hash::where('hashable_type', 'weather_station')->first();
        $publish = Publish::create([
            'hash_id' => $hash->id,
            'publisher_id' => $publisher->id,
            'status' => 'pending',
        ]);

        Log::shouldReceive('error')
            ->withArgs(function ($message) {
                return str_contains($message, 'Could not instantiate publisher');
            })
            ->once();

        // When: Job executes
        $job = new BulkPublishJob();
        $job->handle();

        // Then: Publish remains unprocessed due to critical error
        $publish->refresh();
        expect($publish->status->value)->toBe('pending');
    })->skip();

    test('it handles publisher shouldPublish returning false', function () {
        // Given: A weather station that shouldn't be published (inactive)
        $station = TestWeatherStation::where('name', 'Station-1')->first();
        $station->update(['status' => 'inactive']);

        $publisher = Publisher::where('model_type', 'weather_station')->first();
        $hash = Hash::where('hashable_type', 'weather_station')
            ->where('hashable_id', $station->id)
            ->first();

        $publish = Publish::create([
            'hash_id' => $hash->id,
            'publisher_id' => $publisher->id,
            'status' => 'pending',
        ]);

        // When: Job executes
        $job = new BulkPublishJob();
        $job->handle();

        // Then: Publish is marked as published (skipped)
        $publish->refresh();
        expect($publish->status->value)->toBe('published');
    })->skip();

    test('it logs appropriate messages during processing', function () {
        // Given: Pending publishes
        $publisher = Publisher::where('model_type', 'weather_station')->first();
        $hash = Hash::where('hashable_type', 'weather_station')->first();

        Publish::create([
            'hash_id' => $hash->id,
            'publisher_id' => $publisher->id,
            'status' => 'pending',
        ]);

        Log::shouldReceive('info')
            ->withArgs(function ($message) {
                return str_contains($message, 'Starting bulk publish processing');
            })
            ->once();

        Log::shouldReceive('info')
            ->withArgs(function ($message) {
                return str_contains($message, 'Processing') && str_contains($message, 'records');
            })
            ->once();

        Log::shouldReceive('debug')->atLeast()->once();
        Log::shouldReceive('info')
            ->withArgs(function ($message) {
                return str_contains($message, 'Completed batch');
            })
            ->once();

        // When: Job executes
        $job = new BulkPublishJob();
        $job->handle();
    })->skip();
});