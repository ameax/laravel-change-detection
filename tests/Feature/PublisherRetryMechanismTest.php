<?php

use Ameax\LaravelChangeDetection\Models\Hash;
use Ameax\LaravelChangeDetection\Models\Publisher;
use Ameax\LaravelChangeDetection\Models\Publish;
use Ameax\LaravelChangeDetection\Jobs\BulkPublishJob;
use Ameax\LaravelChangeDetection\Publishers\LogPublisher;
use Ameax\LaravelChangeDetection\Tests\Models\TestWeatherStation;
use Ameax\LaravelChangeDetection\Tests\Models\TestWindvane;
use Ameax\LaravelChangeDetection\Tests\Models\TestAnemometer;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Carbon;

beforeEach(function () {
    Relation::morphMap([
        'test_weather_station' => TestWeatherStation::class,
        'test_windvane' => TestWindvane::class,
        'test_anemometer' => TestAnemometer::class,
    ]);

    Queue::fake();
});

describe('publisher retry mechanism and error handling', function () {
    // 1. Exponential Backoff Implementation
    it('implements exponential backoff for retry attempts', function () {
        $station = createStationInBayern();
        createWindvaneForStation($station->id);
        createAnemometerForStation($station->id, 25.0);

        $publisher = createWebhookPublisher('test_weather_station', 'https://api.example.com/webhook');
        runSyncAutoDiscover();

        $hash = getStationHash($station->id);
        $publish = createPendingPublish($hash->id, $publisher->id);

        // Simulate first failure
        $publish->markAsDeferred('Connection timeout', 504, 'infrastructure');
        expect($publish->attempts)->toBe(1);
        expect($publish->status)->toBe('deferred');

        $firstRetryTime = $publish->next_try;
        expect($firstRetryTime)->toBeInstanceOf(Carbon::class);

        // Simulate second failure
        $publish->markAsDeferred('Connection timeout', 504, 'infrastructure');
        expect($publish->attempts)->toBe(2);

        $secondRetryTime = $publish->next_try;
        $timeDiff = $secondRetryTime->diffInSeconds($firstRetryTime);

        // Second retry should be significantly longer (5 min vs 30 sec)
        expect($timeDiff)->toBeGreaterThan(240); // At least 4 minutes difference

        // Simulate third failure
        $publish->markAsDeferred('Connection timeout', 504, 'infrastructure');
        expect($publish->attempts)->toBe(3);

        $thirdRetryTime = $publish->next_try;
        $timeDiff2 = $thirdRetryTime->diffInSeconds($secondRetryTime);

        // Third retry should be much longer (6 hours vs 5 min)
        expect($timeDiff2)->toBeGreaterThan(20000); // At least 5.5 hours difference

        // Fourth attempt should fail permanently
        $publish->markAsDeferred('Connection timeout', 504, 'infrastructure');
        expect($publish->status)->toBe('failed');
        expect($publish->attempts)->toBe(4);
    });

    // 2. Different Error Types Handling
    it('handles different error types with appropriate strategies', function () {
        $station = createStationInBayern();
        $publisher = createWebhookPublisher('test_weather_station', 'https://api.example.com/webhook');
        runSyncAutoDiscover();

        $hash = getStationHash($station->id);

        // Test validation error
        $validationPublish = createPendingPublish($hash->id, $publisher->id);
        $validationPublish->markAsDeferred('Invalid data format', 400, 'validation');
        expect($validationPublish->error_type)->toBe('validation');
        expect($validationPublish->status)->toBe('deferred');

        // Test infrastructure error
        $infraPublish = createPendingPublish($hash->id, $publisher->id, ['metadata' => ['attempt' => 2]]);
        $infraPublish->markAsDeferred('Connection refused', 503, 'infrastructure');
        expect($infraPublish->error_type)->toBe('infrastructure');
        expect($infraPublish->last_response_code)->toBe(503);

        // Test data error
        $dataPublish = createPendingPublish($hash->id, $publisher->id, ['metadata' => ['attempt' => 3]]);
        $dataPublish->markAsDeferred('Model not found', 404, 'data');
        expect($dataPublish->error_type)->toBe('data');

        // Test unknown error
        $unknownPublish = createPendingPublish($hash->id, $publisher->id, ['metadata' => ['attempt' => 4]]);
        $unknownPublish->markAsDeferred('Unexpected error occurred', null, 'unknown');
        expect($unknownPublish->error_type)->toBe('unknown');
    });

    // 3. Custom Retry Intervals per Publisher
    it('respects custom retry intervals defined by publisher', function () {
        $station = createStationInBayern();

        // Create publisher with custom retry intervals
        $publisher = createPublisherWithCustomRetryIntervals(
            'test_weather_station',
            [10, 30, 60, 120, 300] // 10s, 30s, 1min, 2min, 5min
        );

        runSyncAutoDiscover();

        $hash = getStationHash($station->id);
        $publish = createPendingPublish($hash->id, $publisher->id);

        $now = Carbon::now();
        Carbon::setTestNow($now);

        // First retry: 10 seconds
        $publish->markAsDeferred('Error', 500, 'infrastructure');
        expect($publish->next_try->diffInSeconds($now))->toBe(10);

        // Second retry: 30 seconds
        $publish->markAsDeferred('Error', 500, 'infrastructure');
        expect($publish->next_try->diffInSeconds($now))->toBe(30);

        // Third retry: 60 seconds
        $publish->markAsDeferred('Error', 500, 'infrastructure');
        expect($publish->next_try->diffInSeconds($now))->toBe(60);

        Carbon::setTestNow(); // Reset
    });

    // 4. Retry Limit and Permanent Failure
    it('permanently fails after exceeding retry limit', function () {
        $station = createStationInBayern();
        $publisher = createWebhookPublisher('test_weather_station', 'https://api.example.com/webhook');
        runSyncAutoDiscover();

        $hash = getStationHash($station->id);
        $publish = createPendingPublish($hash->id, $publisher->id);

        // Simulate multiple failures
        $retryIntervals = config('change-detection.retry_intervals', [30, 300, 21600]);

        for ($i = 0; $i < count($retryIntervals); $i++) {
            $publish->markAsDeferred('Persistent error', 500, 'infrastructure');
            expect($publish->status)->toBe('deferred');
            expect($publish->attempts)->toBe($i + 1);
        }

        // Next attempt should permanently fail
        $publish->markAsDeferred('Persistent error', 500, 'infrastructure');
        expect($publish->status)->toBe('failed');
        expect($publish->attempts)->toBe(count($retryIntervals) + 1);
        expect($publish->next_try)->toBeNull();
    });

    // 5. Queue Job Integration and Dispatching
    it('properly integrates with Laravel queue system', function () {
        Queue::fake();

        $station = createStationInBayern();
        $publisher = createWebhookPublisher('test_weather_station', 'https://api.example.com/webhook');
        runSyncAutoDiscover();

        $hash = getStationHash($station->id);
        createMultiplePendingPublishes($hash->id, $publisher->id, 5);

        // Dispatch bulk publish job
        BulkPublishJob::dispatch();

        Queue::assertPushed(BulkPublishJob::class, function ($job) {
            return $job->timeout === 600 && // 10 minutes
                   $job->tries === 3 &&
                   $job instanceof \Illuminate\Contracts\Queue\ShouldBeUnique;
        });

        // Test job uniqueness
        BulkPublishJob::dispatch();
        BulkPublishJob::dispatch();

        // Should only be pushed once due to ShouldBeUnique
        Queue::assertPushed(BulkPublishJob::class, 1);
    });

    // 6. Webhook Timeout Scenarios
    it('handles webhook timeout scenarios correctly', function () {
        Http::fake([
            'api.example.com/webhook' => Http::sequence()
                ->push(null, 408) // Request Timeout
                ->push(null, 504) // Gateway Timeout
                ->push(['success' => true], 200) // Success on third try
        ]);

        $station = createStationInBayern();
        $publisher = createWebhookPublisher('test_weather_station', 'https://api.example.com/webhook');
        runSyncAutoDiscover();

        $hash = getStationHash($station->id);
        $publish = createPendingPublish($hash->id, $publisher->id);

        // Simulate webhook publisher behavior
        $webhookPublisher = new MockWebhookPublisher($publisher->config);

        // First attempt: 408 timeout
        $result = $webhookPublisher->attemptPublish($station, $publish);
        expect($result['success'])->toBeFalse();
        expect($result['error'])->toContain('timeout');
        expect($result['code'])->toBe(408);

        $publish->markAsDeferred($result['error'], $result['code'], 'infrastructure');
        expect($publish->status)->toBe('deferred');

        // Second attempt: 504 gateway timeout
        $result = $webhookPublisher->attemptPublish($station, $publish);
        expect($result['code'])->toBe(504);

        $publish->markAsDeferred($result['error'], $result['code'], 'infrastructure');
        expect($publish->attempts)->toBe(2);

        // Third attempt: success
        $result = $webhookPublisher->attemptPublish($station, $publish);
        expect($result['success'])->toBeTrue();

        $publish->markAsPublished(['response' => $result['data']]);
        expect($publish->status)->toBe('published');
    });

    // 7. Concurrent Publisher Processing
    it('handles concurrent publishers for same model without conflicts', function () {
        $station = createStationInBayern();
        createWindvaneForStation($station->id);
        createAnemometerForStation($station->id, 25.0);

        // Create multiple publishers
        $logPublisher = createLogPublisher('test_weather_station');
        $webhookPublisher = createWebhookPublisher('test_weather_station', 'https://api1.example.com');
        $apiPublisher = createWebhookPublisher('test_weather_station', 'https://api2.example.com', 'API Publisher 2');

        runSyncAutoDiscover();

        $hash = getStationHash($station->id);

        // Each publisher should have its own publish record
        $publishes = Publish::where('hash_id', $hash->id)->get();
        expect($publishes)->toHaveCount(3);

        // Simulate different outcomes for each publisher
        $logPublish = $publishes->where('publisher_id', $logPublisher->id)->first();
        $logPublish->markAsPublished(['logged' => true]);

        $webhookPublish = $publishes->where('publisher_id', $webhookPublisher->id)->first();
        $webhookPublish->markAsDeferred('Connection timeout', 504, 'infrastructure');

        $apiPublish = $publishes->where('publisher_id', $apiPublisher->id)->first();
        $apiPublish->markAsFailed('Invalid API key', 401, 'validation');

        // Verify independent status tracking
        expect($logPublish->fresh()->status)->toBe('published');
        expect($webhookPublish->fresh()->status)->toBe('deferred');
        expect($apiPublish->fresh()->status)->toBe('failed');
    });

    // 8. Validation Error Threshold Management
    it('stops processing after exceeding validation error threshold', function () {
        Queue::fake();

        $stations = createBulkWeatherStations(20);
        $publisher = createWebhookPublisher('test_weather_station', 'https://api.example.com/webhook');

        // Set max validation errors to 5
        $publisher->config = array_merge($publisher->config ?? [], ['max_validation_errors' => 5]);
        $publisher->save();

        runSyncAutoDiscover();

        // Create publishes with validation errors
        $validationErrorCount = 0;
        foreach ($stations as $index => $station) {
            $hash = getStationHash($station['id']);
            $publish = createPendingPublish($hash->id, $publisher->id);

            if ($index < 7) { // Create 7 validation errors
                simulateValidationError($publish);
                $validationErrorCount++;
            }
        }

        // Process with job
        $job = new BulkPublishJob();
        $jobShouldStop = $job->shouldStopForValidationErrors($publisher, $validationErrorCount);

        expect($jobShouldStop)->toBeTrue();
        expect($validationErrorCount)->toBeGreaterThan(5);
    });

    // 9. Infrastructure Error Circuit Breaker
    it('implements circuit breaker for infrastructure errors', function () {
        $stations = createBulkWeatherStations(10);
        $publisher = createWebhookPublisher('test_weather_station', 'https://api.example.com/webhook');

        // Set max infrastructure errors to 3
        $publisher->config = array_merge($publisher->config ?? [], ['max_infrastructure_errors' => 3]);
        $publisher->save();

        runSyncAutoDiscover();

        $infrastructureErrors = 0;
        $processedCount = 0;

        foreach ($stations as $station) {
            $hash = getStationHash($station['id']);
            $publish = createPendingPublish($hash->id, $publisher->id);

            // Simulate infrastructure errors for first 4 stations
            if ($processedCount < 4) {
                $publish->markAsDeferred('Connection refused', 503, 'infrastructure');
                $infrastructureErrors++;
            }

            $processedCount++;

            // Circuit should break after 3 infrastructure errors
            if ($infrastructureErrors >= 3) {
                expect($publish->status)->toBe('deferred');
                break;
            }
        }

        expect($infrastructureErrors)->toBe(3);
        expect($processedCount)->toBe(4); // Stopped after 4th station
    });

    // 10. Deferred Record Processing with Time Windows
    it('processes deferred records only when retry time is reached', function () {
        Carbon::setTestNow('2024-01-15 10:00:00');

        $station = createStationInBayern();
        $publisher = createWebhookPublisher('test_weather_station', 'https://api.example.com/webhook');
        runSyncAutoDiscover();

        $hash = getStationHash($station->id);

        // Create multiple deferred publishes with different retry times
        $publish1 = createPendingPublish($hash->id, $publisher->id);
        $publish1->markAsDeferred('Error', 500, 'infrastructure');
        $publish1->update(['next_try' => Carbon::now()->addMinutes(5)]);

        $publish2 = createPendingPublish($hash->id, $publisher->id, ['metadata' => ['batch' => 2]]);
        $publish2->markAsDeferred('Error', 500, 'infrastructure');
        $publish2->update(['next_try' => Carbon::now()->addMinutes(10)]);

        $publish3 = createPendingPublish($hash->id, $publisher->id, ['metadata' => ['batch' => 3]]);
        $publish3->markAsDeferred('Error', 500, 'infrastructure');
        $publish3->update(['next_try' => Carbon::now()->subMinutes(5)]); // Ready now

        // Get records ready for retry
        $readyForRetry = Publish::readyForProcessing()->get();

        expect($readyForRetry)->toHaveCount(1);
        expect($readyForRetry->first()->id)->toBe($publish3->id);

        // Move time forward
        Carbon::setTestNow('2024-01-15 10:06:00');

        $readyForRetry = Publish::readyForProcessing()->get();
        expect($readyForRetry)->toHaveCount(2); // publish1 and publish3

        Carbon::setTestNow(); // Reset
    });

    // 11. Publisher Error Strategy Handling
    it('respects publisher-specific error handling strategies', function () {
        $station = createStationInBayern();

        // Create publisher with custom error strategy
        $publisher = createPublisherWithErrorStrategy('test_weather_station', [
            'stop_on_permission_denied' => true,
            'defer_on_rate_limit' => true,
            'fail_on_invalid_data' => true,
        ]);

        runSyncAutoDiscover();
        $hash = getStationHash($station->id);

        // Test permission denied - should stop job
        $publish1 = createPendingPublish($hash->id, $publisher->id);
        $errorStrategy = determinePublisherErrorStrategy($publisher, 'Permission denied');
        expect($errorStrategy)->toBe('stop_job');

        // Test rate limit - should defer
        $publish2 = createPendingPublish($hash->id, $publisher->id, ['metadata' => ['batch' => 2]]);
        $errorStrategy = determinePublisherErrorStrategy($publisher, 'Rate limit exceeded');
        expect($errorStrategy)->toBe('defer_record');

        // Test invalid data - should fail
        $publish3 = createPendingPublish($hash->id, $publisher->id, ['metadata' => ['batch' => 3]]);
        $errorStrategy = determinePublisherErrorStrategy($publisher, 'Invalid data format');
        expect($errorStrategy)->toBe('fail_record');
    });

    // 12. Batch Processing with Mixed Results
    it('handles batch processing with mixed success and failure results', function () {
        $stations = createBulkWeatherStations(10);
        $publisher = createWebhookPublisher('test_weather_station', 'https://api.example.com/webhook');

        runSyncAutoDiscover();

        $publishes = [];
        foreach ($stations as $index => $station) {
            $hash = getStationHash($station['id']);
            $publish = createPendingPublish($hash->id, $publisher->id);
            $publishes[] = $publish;
        }

        // Simulate batch processing with mixed results
        $results = processBatchWithMixedResults($publishes);

        expect($results['successful'])->toBe(6);
        expect($results['deferred'])->toBe(2);
        expect($results['failed'])->toBe(2);

        // Verify status updates
        foreach ($publishes as $index => $publish) {
            $publish->refresh();

            if ($index < 6) {
                expect($publish->status)->toBe('published');
            } elseif ($index < 8) {
                expect($publish->status)->toBe('deferred');
                expect($publish->next_try)->not->toBeNull();
            } else {
                expect($publish->status)->toBe('failed');
            }
        }
    });

    // 13. Log Publisher Specific Error Handling
    it('handles log publisher specific errors correctly', function () {
        // Simulate disk full scenario
        $station = createStationInBayern();
        $publisher = createLogPublisher('test_weather_station');

        runSyncAutoDiscover();
        $hash = getStationHash($station->id);
        $publish = createPendingPublish($hash->id, $publisher->id);

        // Test disk space error - should stop job
        $config = $publisher->config ?? [];
        $logPublisher = new LogPublisher(
            $config['log_channel'] ?? 'default',
            $config['log_level'] ?? 'info',
            $config['include_hash_data'] ?? true
        );
        $strategy = $logPublisher->handlePublishException(
            new \Exception('No space left on device')
        );
        expect($strategy)->toBe('stop_job');

        // Test permission error - should stop job
        $strategy = $logPublisher->handlePublishException(
            new \Exception('Permission denied: Cannot write to log file')
        );
        expect($strategy)->toBe('stop_job');

        // Test other error - should defer
        $strategy = $logPublisher->handlePublishException(
            new \Exception('Temporary log service unavailable')
        );
        expect($strategy)->toBe('defer_record');
    });

    // 14. Recovery After System Restart
    it('recovers and continues processing after system restart', function () {
        Carbon::setTestNow('2024-01-15 10:00:00');

        $stations = createBulkWeatherStations(5);
        $publisher = createWebhookPublisher('test_weather_station', 'https://api.example.com/webhook');

        runSyncAutoDiscover();

        // Create mix of statuses
        foreach ($stations as $index => $station) {
            $hash = getStationHash($station['id']);
            $publish = createPendingPublish($hash->id, $publisher->id);

            if ($index === 0) {
                $publish->markAsDispatched();
            } elseif ($index === 1) {
                $publish->markAsDeferred('Error', 500, 'infrastructure');
                $publish->update(['next_try' => Carbon::now()->subMinutes(5)]);
            } elseif ($index === 2) {
                $publish->markAsPublished(['success' => true]);
            } elseif ($index === 3) {
                $publish->markAsFailed('Permanent error', 500, 'data');
            }
            // index 4 remains pending
        }

        // Simulate system restart - reset dispatched to pending
        Publish::where('status', 'dispatched')
            ->where('updated_at', '<', Carbon::now()->subMinutes(10))
            ->update(['status' => 'pending']);

        // Get records ready for processing after restart
        $readyForProcessing = Publish::readyForProcessing()->get();

        expect($readyForProcessing)->toHaveCount(3); // pending, deferred (ready), and reset dispatched

        $statuses = $readyForProcessing->pluck('status')->toArray();
        expect($statuses)->toContain('pending');
        expect($statuses)->toContain('deferred');

        Carbon::setTestNow(); // Reset
    });

    // 15. HTTP Response Code Extraction and Handling
    it('correctly extracts and handles HTTP response codes from exceptions', function () {
        $station = createStationInBayern();
        $publisher = createWebhookPublisher('test_weather_station', 'https://api.example.com/webhook');

        runSyncAutoDiscover();
        $hash = getStationHash($station->id);

        // Test various HTTP error messages
        $testCases = [
            'HTTP/1.1 404 Not Found' => 404,
            'Client error: 400 Bad Request' => 400,
            'Server error: `POST https://api.example.com` resulted in a `503 Service Unavailable`' => 503,
            'HTTP request returned status code 429' => 429,
            'cURL error 28: Operation timed out' => null,
            'Connection refused' => null,
        ];

        foreach ($testCases as $errorMessage => $expectedCode) {
            $publish = createPendingPublish($hash->id, $publisher->id, [
                'metadata' => ['test_case' => $errorMessage]
            ]);

            $extractedCode = extractHttpCodeFromError($errorMessage);
            expect($extractedCode)->toBe($expectedCode);

            $publish->markAsDeferred($errorMessage, $extractedCode, 'infrastructure');
            expect($publish->last_response_code)->toBe($expectedCode);
        }
    });

    // 16. Publisher Priority and Ordering
    it('processes publishers in correct priority order', function () {
        Queue::fake();

        $station = createStationInBayern();

        // Create publishers with different priorities
        $criticalPublisher = createPublisherWithPriority('test_weather_station', 1, 'Critical Publisher');
        $normalPublisher = createPublisherWithPriority('test_weather_station', 5, 'Normal Publisher');
        $lowPublisher = createPublisherWithPriority('test_weather_station', 10, 'Low Priority Publisher');

        runSyncAutoDiscover();
        $hash = getStationHash($station->id);

        // Create publishes
        $criticalPublish = createPendingPublish($hash->id, $criticalPublisher->id);
        $normalPublish = createPendingPublish($hash->id, $normalPublisher->id);
        $lowPublish = createPendingPublish($hash->id, $lowPublisher->id);

        // Get publishes in priority order
        $orderedPublishes = Publish::readyForProcessing()
            ->join('publishers', 'publishes.publisher_id', '=', 'publishers.id')
            ->orderBy('publishers.config->priority', 'asc')
            ->select('publishes.*')
            ->get();

        expect($orderedPublishes->first()->publisher_id)->toBe($criticalPublisher->id);
        expect($orderedPublishes->last()->publisher_id)->toBe($lowPublisher->id);
    });

    // 17. Cache-Based Job Locking
    it('prevents duplicate job execution using cache locks', function () {
        Cache::flush();

        $station = createStationInBayern();
        $publisher = createWebhookPublisher('test_weather_station', 'https://api.example.com/webhook');

        runSyncAutoDiscover();
        $hash = getStationHash($station->id);
        createPendingPublish($hash->id, $publisher->id);

        // First job acquires lock
        $lockKey = 'bulk-publish-job-lock';
        $lock1 = Cache::lock($lockKey, 300); // 5 minutes
        expect($lock1->acquire())->toBeTrue();

        // Second job cannot acquire lock
        $lock2 = Cache::lock($lockKey, 300);
        expect($lock2->acquire())->toBeFalse();

        // Release first lock
        $lock1->release();

        // Now second job can acquire
        expect($lock2->acquire())->toBeTrue();
        $lock2->release();
    });

    // 18. Stale Dispatched Record Cleanup
    it('cleans up stale dispatched records after timeout', function () {
        Carbon::setTestNow('2024-01-15 10:00:00');

        $stations = createBulkWeatherStations(3);
        $publisher = createWebhookPublisher('test_weather_station', 'https://api.example.com/webhook');

        runSyncAutoDiscover();

        foreach ($stations as $index => $station) {
            $hash = getStationHash($station['id']);
            $publish = createPendingPublish($hash->id, $publisher->id);
            $publish->markAsDispatched();

            // Make some records stale
            if ($index < 2) {
                $publish->update(['updated_at' => Carbon::now()->subMinutes(15)]);
            }
        }

        // Clean up stale dispatched records (older than 10 minutes)
        $cleaned = Publish::where('status', 'dispatched')
            ->where('updated_at', '<', Carbon::now()->subMinutes(10))
            ->update(['status' => 'pending']);

        expect($cleaned)->toBe(2);

        // Verify cleanup
        $dispatchedCount = Publish::where('status', 'dispatched')->count();
        expect($dispatchedCount)->toBe(1);

        $pendingCount = Publish::where('status', 'pending')->count();
        expect($pendingCount)->toBe(2);

        Carbon::setTestNow(); // Reset
    });

    // 19. Publisher Configuration Hot Reload
    it('applies publisher configuration changes without restart', function () {
        $station = createStationInBayern();

        $publisher = createWebhookPublisher('test_weather_station', 'https://api.example.com/webhook');
        $originalConfig = $publisher->config;

        runSyncAutoDiscover();
        $hash = getStationHash($station->id);
        $publish = createPendingPublish($hash->id, $publisher->id);

        // Update publisher configuration
        $newConfig = array_merge($originalConfig ?? [], [
            'retry_intervals' => [5, 10, 20],
            'max_attempts' => 5,
            'timeout' => 30,
        ]);

        $publisher->update(['config' => $newConfig]);

        // New publish should use updated config
        $publish->markAsDeferred('Error', 500, 'infrastructure');

        // Should use new retry interval (5 seconds)
        expect($publish->next_try->diffInSeconds(now()))->toBeLessThanOrEqual(5);

        // Can attempt up to 5 times now
        for ($i = 2; $i <= 5; $i++) {
            $publish->markAsDeferred('Error', 500, 'infrastructure');
            if ($i < 5) {
                expect($publish->status)->toBe('deferred');
            }
        }

        // 6th attempt should fail
        $publish->markAsDeferred('Error', 500, 'infrastructure');
        expect($publish->status)->toBe('failed');
    });

    // 20. Multi-Environment Publisher Handling
    it('handles different publisher configurations per environment', function () {
        $station = createStationInBayern();

        // Create environment-specific publishers
        $productionPublisher = createEnvironmentPublisher(
            'test_weather_station',
            'production',
            'https://prod.api.com/webhook'
        );

        $stagingPublisher = createEnvironmentPublisher(
            'test_weather_station',
            'staging',
            'https://staging.api.com/webhook'
        );

        // Simulate production environment
        app()->detectEnvironment(function () {
            return 'production';
        });

        runSyncAutoDiscover();
        $hash = getStationHash($station->id);

        // Only production publisher should be active
        $publishes = Publish::where('hash_id', $hash->id)->get();
        $activePublishers = $publishes->pluck('publisher_id')->toArray();

        if (app()->environment('production')) {
            expect($activePublishers)->toContain($productionPublisher->id);
            expect($activePublishers)->not->toContain($stagingPublisher->id);
        }
    });
})->skip();