<?php

use Ameax\LaravelChangeDetection\Enums\PublishStatusEnum;
use Ameax\LaravelChangeDetection\Models\Hash;
use Ameax\LaravelChangeDetection\Models\Publish;
use Ameax\LaravelChangeDetection\Models\Publisher;
use Ameax\LaravelChangeDetection\Tests\Models\TestCar;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Config;

beforeEach(function () {
    Relation::morphMap([
        'test_car' => TestCar::class,
    ]);
});

describe('publish retry functionality', function () {
    it('marks publish as deferred with retry time when initial attempt fails', function () {
        // Set up retry intervals
        Config::set('change-detection.retry_intervals', [
            1 => 30,   // First retry after 30 seconds
            2 => 300,  // Second retry after 5 minutes
            3 => 3600, // Third retry after 1 hour
        ]);

        $car = TestCar::create([
            'model' => 'Tesla Model 3',
            'year' => 2023,
            'price' => 45000,
            'is_electric' => true,
        ]);

        $hash = Hash::create([
            'hashable_type' => 'test_car',
            'hashable_id' => $car->id,
            'attribute_hash' => 'test_hash',
            'composite_hash' => 'test_composite',
        ]);

        $publisher = Publisher::create([
            'name' => 'Test Publisher',
            'model_type' => 'test_car',
            'publisher_class' => 'TestPublisher',
            'enabled' => true,
        ]);

        $publish = Publish::create([
            'hash_id' => $hash->id,
            'publisher_id' => $publisher->id,
            'status' => PublishStatusEnum::PENDING,
            'attempts' => 0,
        ]);

        // Simulate first failure
        $publish->markAsDeferred('Connection timeout', 408, 'infrastructure');

        expect($publish->status)->toBe(PublishStatusEnum::DEFERRED);
        expect($publish->attempts)->toBe(1);
        expect($publish->last_error)->toBe('Connection timeout');
        expect($publish->last_response_code)->toBe(408);
        expect($publish->error_type)->toBe('infrastructure');
        expect($publish->next_try)->not->toBeNull();

        // Next retry should be approximately 30 seconds from now
        $diffInSeconds = now()->diffInSeconds($publish->next_try);
        expect($diffInSeconds)->toBeGreaterThanOrEqual(29);
        expect($diffInSeconds)->toBeLessThanOrEqual(31);
    });

    it('increases retry interval with each subsequent failure', function () {
        Config::set('change-detection.retry_intervals', [
            1 => 60,
            2 => 600,
            3 => 3600,
        ]);

        $car = TestCar::create([
            'model' => 'BMW i4',
            'year' => 2023,
            'price' => 60000,
            'is_electric' => true,
        ]);

        $hash = Hash::create([
            'hashable_type' => 'test_car',
            'hashable_id' => $car->id,
            'attribute_hash' => 'test_hash',
            'composite_hash' => 'test_composite',
        ]);

        $publisher = Publisher::create([
            'name' => 'BMW Publisher',
            'model_type' => 'test_car',
            'publisher_class' => 'TestPublisher',
            'enabled' => true,
        ]);

        $publish = Publish::create([
            'hash_id' => $hash->id,
            'publisher_id' => $publisher->id,
            'status' => PublishStatusEnum::PENDING,
            'attempts' => 0,
        ]);

        // First failure - 60 second retry
        $publish->markAsDeferred('Error 1', null, null);
        expect($publish->attempts)->toBe(1);
        $firstRetryTime = $publish->next_try;
        expect(now()->diffInSeconds($firstRetryTime))->toBeGreaterThanOrEqual(59);

        // Second failure - 600 second retry
        $publish->markAsDeferred('Error 2', null, null);
        expect($publish->attempts)->toBe(2);
        $secondRetryTime = $publish->next_try;
        expect(now()->diffInSeconds($secondRetryTime))->toBeGreaterThanOrEqual(599);

        // Third failure - 3600 second retry
        $publish->markAsDeferred('Error 3', null, null);
        expect($publish->attempts)->toBe(3);
        $thirdRetryTime = $publish->next_try;
        expect(now()->diffInSeconds($thirdRetryTime))->toBeGreaterThanOrEqual(3599);
    });

    it('marks publish as failed after exceeding max retries', function () {
        Config::set('change-detection.retry_intervals', [
            1 => 10,
            2 => 20,
            3 => 30,
        ]);

        $car = TestCar::create([
            'model' => 'Audi e-tron',
            'year' => 2023,
            'price' => 70000,
            'is_electric' => true,
        ]);

        $hash = Hash::create([
            'hashable_type' => 'test_car',
            'hashable_id' => $car->id,
            'attribute_hash' => 'test_hash',
            'composite_hash' => 'test_composite',
        ]);

        $publisher = Publisher::create([
            'name' => 'Audi Publisher',
            'model_type' => 'test_car',
            'publisher_class' => 'TestPublisher',
            'enabled' => true,
        ]);

        $publish = Publish::create([
            'hash_id' => $hash->id,
            'publisher_id' => $publisher->id,
            'status' => PublishStatusEnum::PENDING,
            'attempts' => 3, // Already at max attempts
        ]);

        // This should mark as failed since we're at max retries
        $publish->markAsDeferred('Final error', 500, 'infrastructure');

        expect($publish->status)->toBe(PublishStatusEnum::FAILED);
        expect($publish->attempts)->toBe(4); // Incremented to 4
        expect($publish->last_error)->toBe('Final error');
        expect($publish->last_response_code)->toBe(500);
        expect($publish->error_type)->toBe('infrastructure');
        expect($publish->next_try)->toBeNull();
    });

    it('correctly identifies when a deferred publish should be retried', function () {
        $car = TestCar::create([
            'model' => 'Nissan Leaf',
            'year' => 2023,
            'price' => 35000,
            'is_electric' => true,
        ]);

        $hash = Hash::create([
            'hashable_type' => 'test_car',
            'hashable_id' => $car->id,
            'attribute_hash' => 'test_hash',
            'composite_hash' => 'test_composite',
        ]);

        // Create multiple publishers to avoid unique constraint issues
        $publisher1 = Publisher::create([
            'name' => 'Nissan Publisher 1',
            'model_type' => 'test_car',
            'publisher_class' => 'TestPublisher',
            'enabled' => true,
        ]);

        $publisher2 = Publisher::create([
            'name' => 'Nissan Publisher 2',
            'model_type' => 'test_car',
            'publisher_class' => 'TestPublisher',
            'enabled' => true,
        ]);

        $publisher3 = Publisher::create([
            'name' => 'Nissan Publisher 3',
            'model_type' => 'test_car',
            'publisher_class' => 'TestPublisher',
            'enabled' => true,
        ]);

        // Create a deferred publish with next_try in the past
        $publish = Publish::create([
            'hash_id' => $hash->id,
            'publisher_id' => $publisher1->id,
            'status' => PublishStatusEnum::DEFERRED,
            'attempts' => 1,
            'next_try' => now()->subMinutes(5), // 5 minutes ago
        ]);

        expect($publish->shouldRetry())->toBeTrue();

        // Create another deferred publish with next_try in the future
        $publish2 = Publish::create([
            'hash_id' => $hash->id,
            'publisher_id' => $publisher2->id,
            'status' => PublishStatusEnum::DEFERRED,
            'attempts' => 1,
            'next_try' => now()->addMinutes(5), // 5 minutes from now
        ]);

        expect($publish2->shouldRetry())->toBeFalse();

        // Test pending status should not retry
        $publish3 = Publish::create([
            'hash_id' => $hash->id,
            'publisher_id' => $publisher3->id,
            'status' => PublishStatusEnum::PENDING,
            'attempts' => 0,
        ]);

        expect($publish3->shouldRetry())->toBeFalse();
    });

    it('handles successful publish after retry', function () {
        $car = TestCar::create([
            'model' => 'Volkswagen ID.4',
            'year' => 2023,
            'price' => 42000,
            'is_electric' => true,
        ]);

        $hash = Hash::create([
            'hashable_type' => 'test_car',
            'hashable_id' => $car->id,
            'attribute_hash' => 'test_hash',
            'composite_hash' => 'test_composite',
        ]);

        $publisher = Publisher::create([
            'name' => 'VW Publisher',
            'model_type' => 'test_car',
            'publisher_class' => 'TestPublisher',
            'enabled' => true,
        ]);

        $publish = Publish::create([
            'hash_id' => $hash->id,
            'publisher_id' => $publisher->id,
            'status' => PublishStatusEnum::DEFERRED,
            'attempts' => 2,
            'last_error' => 'Previous error',
            'next_try' => now()->subMinutes(1),
        ]);

        // Mark as successfully published
        $publish->markAsPublished();

        expect($publish->status)->toBe(PublishStatusEnum::PUBLISHED);
        expect($publish->published_hash)->toBe($hash->composite_hash);
        expect($publish->published_at)->not->toBeNull();
        expect($publish->last_error)->toBeNull(); // Error cleared on success
    });

    it('uses scopePendingOrDeferred to find records ready for processing', function () {
        $car = TestCar::create([
            'model' => 'Ford Mustang Mach-E',
            'year' => 2023,
            'price' => 55000,
            'is_electric' => true,
        ]);

        $hash = Hash::create([
            'hashable_type' => 'test_car',
            'hashable_id' => $car->id,
            'attribute_hash' => 'test_hash',
            'composite_hash' => 'test_composite',
        ]);

        // Create multiple publishers to avoid unique constraint
        $publishers = [];
        for ($i = 1; $i <= 5; $i++) {
            $publishers[$i] = Publisher::create([
                'name' => "Ford Publisher {$i}",
                'model_type' => 'test_car',
                'publisher_class' => 'TestPublisher',
                'enabled' => true,
            ]);
        }

        // Create various publish records
        $pending = Publish::create([
            'hash_id' => $hash->id,
            'publisher_id' => $publishers[1]->id,
            'status' => PublishStatusEnum::PENDING,
            'attempts' => 0,
        ]);

        $deferredReady = Publish::create([
            'hash_id' => $hash->id,
            'publisher_id' => $publishers[2]->id,
            'status' => PublishStatusEnum::DEFERRED,
            'attempts' => 1,
            'next_try' => now()->subMinutes(1), // Past - ready for retry
        ]);

        $deferredNotReady = Publish::create([
            'hash_id' => $hash->id,
            'publisher_id' => $publishers[3]->id,
            'status' => PublishStatusEnum::DEFERRED,
            'attempts' => 1,
            'next_try' => now()->addMinutes(10), // Future - not ready
        ]);

        $published = Publish::create([
            'hash_id' => $hash->id,
            'publisher_id' => $publishers[4]->id,
            'status' => PublishStatusEnum::PUBLISHED,
            'attempts' => 1,
        ]);

        $failed = Publish::create([
            'hash_id' => $hash->id,
            'publisher_id' => $publishers[5]->id,
            'status' => PublishStatusEnum::FAILED,
            'attempts' => 4,
        ]);

        // Query using scope
        $readyForProcessing = Publish::pendingOrDeferred()->get();

        expect($readyForProcessing)->toHaveCount(2);
        expect($readyForProcessing->pluck('id')->toArray())
            ->toContain($pending->id)
            ->toContain($deferredReady->id)
            ->not->toContain($deferredNotReady->id)
            ->not->toContain($published->id)
            ->not->toContain($failed->id);
    });

    it('handles soft-deleted publish records correctly', function () {
        $car = TestCar::create([
            'model' => 'Hyundai Ioniq 5',
            'year' => 2023,
            'price' => 48000,
            'is_electric' => true,
        ]);

        $hash = Hash::create([
            'hashable_type' => 'test_car',
            'hashable_id' => $car->id,
            'attribute_hash' => 'test_hash',
            'composite_hash' => 'test_composite',
            'deleted_at' => now(), // Hash is soft-deleted
        ]);

        $publisher = Publisher::create([
            'name' => 'Hyundai Publisher',
            'model_type' => 'test_car',
            'publisher_class' => 'TestPublisher',
            'enabled' => true,
        ]);

        $publish = Publish::create([
            'hash_id' => $hash->id,
            'publisher_id' => $publisher->id,
            'status' => PublishStatusEnum::PENDING,
            'attempts' => 0,
        ]);

        // The publishNow method should detect soft-deleted hash and soft-delete the publish
        $result = $publish->publishNow();

        expect($result)->toBeFalse();
        $publish->refresh();
        expect($publish->status)->toBe(PublishStatusEnum::SOFT_DELETED);
    });

    it('preserves error information through retry cycles', function () {
        Config::set('change-detection.retry_intervals', [
            1 => 10,
            2 => 20,
        ]);

        $car = TestCar::create([
            'model' => 'Rivian R1T',
            'year' => 2023,
            'price' => 75000,
            'is_electric' => true,
        ]);

        $hash = Hash::create([
            'hashable_type' => 'test_car',
            'hashable_id' => $car->id,
            'attribute_hash' => 'test_hash',
            'composite_hash' => 'test_composite',
        ]);

        $publisher = Publisher::create([
            'name' => 'Rivian Publisher',
            'model_type' => 'test_car',
            'publisher_class' => 'TestPublisher',
            'enabled' => true,
        ]);

        $publish = Publish::create([
            'hash_id' => $hash->id,
            'publisher_id' => $publisher->id,
            'status' => PublishStatusEnum::PENDING,
            'attempts' => 0,
        ]);

        // First error
        $publish->markAsDeferred('Connection refused', 503, 'infrastructure');
        $publish->refresh();

        expect($publish->last_error)->toBe('Connection refused');
        expect($publish->last_response_code)->toBe(503);
        expect($publish->error_type)->toBe('infrastructure');

        // Second error (overwrites previous)
        $publish->markAsDeferred('Rate limit exceeded', 429, 'validation');
        $publish->refresh();

        expect($publish->last_error)->toBe('Rate limit exceeded');
        expect($publish->last_response_code)->toBe(429);
        expect($publish->error_type)->toBe('validation');
        expect($publish->attempts)->toBe(2);
    });

    it('correctly handles terminal states', function () {
        $car = TestCar::create([
            'model' => 'Lucid Air',
            'year' => 2023,
            'price' => 90000,
            'is_electric' => true,
        ]);

        $hash = Hash::create([
            'hashable_type' => 'test_car',
            'hashable_id' => $car->id,
            'attribute_hash' => 'test_hash',
            'composite_hash' => 'test_composite',
        ]);

        // Create different publishers for each test case
        $publishers = [];
        for ($i = 1; $i <= 5; $i++) {
            $publishers[$i] = Publisher::create([
                'name' => "Lucid Publisher {$i}",
                'model_type' => 'test_car',
                'publisher_class' => 'TestPublisher',
                'enabled' => true,
            ]);
        }

        // Test PUBLISHED state
        $publish1 = Publish::create([
            'hash_id' => $hash->id,
            'publisher_id' => $publishers[1]->id,
            'status' => PublishStatusEnum::PUBLISHED,
        ]);
        expect($publish1->status->isTerminal())->toBeTrue();
        expect($publish1->status->canProcess())->toBeFalse();

        // Test FAILED state
        $publish2 = Publish::create([
            'hash_id' => $hash->id,
            'publisher_id' => $publishers[2]->id,
            'status' => PublishStatusEnum::FAILED,
        ]);
        expect($publish2->status->isTerminal())->toBeTrue();
        expect($publish2->status->canProcess())->toBeFalse();

        // Test SOFT_DELETED state
        $publish3 = Publish::create([
            'hash_id' => $hash->id,
            'publisher_id' => $publishers[3]->id,
            'status' => PublishStatusEnum::SOFT_DELETED,
        ]);
        expect($publish3->status->isTerminal())->toBeTrue();
        expect($publish3->status->canProcess())->toBeFalse();

        // Test PENDING state (non-terminal)
        $publish4 = Publish::create([
            'hash_id' => $hash->id,
            'publisher_id' => $publishers[4]->id,
            'status' => PublishStatusEnum::PENDING,
        ]);
        expect($publish4->status->isTerminal())->toBeFalse();
        expect($publish4->status->canProcess())->toBeTrue();

        // Test DEFERRED state (non-terminal)
        $publish5 = Publish::create([
            'hash_id' => $hash->id,
            'publisher_id' => $publishers[5]->id,
            'status' => PublishStatusEnum::DEFERRED,
        ]);
        expect($publish5->status->isTerminal())->toBeFalse();
        expect($publish5->status->canProcess())->toBeTrue();
    });
});
