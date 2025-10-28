<?php

declare(strict_types=1);

namespace Ameax\LaravelChangeDetection\Jobs;

use Ameax\LaravelChangeDetection\Models\Publish;
use Ameax\LaravelChangeDetection\Models\Publisher;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class BulkPublishJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $maxExceptions = 3;

    private const DEFAULT_BATCH_SIZE = 100;

    private const DEFAULT_DELAY_MS = 50;

    private const LOCK_KEY = 'bulk_publish_job_running';

    public function __construct()
    {
        $this->onQueue(config('change-detection.queues.publish', 'default'));
        $this->timeout = config('change-detection.job_timeout', 1800);
    }

    /**
     * Get unique job identifier to ensure only one instance runs
     */
    public function uniqueId(): string
    {
        return 'bulk_publish_job';
    }

    /**
     * How long to wait before retrying the job if it's unique
     */
    public function uniqueFor(): int
    {
        return config('change-detection.job_unique_for', 2);
    }

    public function handle(): void
    {
        Log::info('BulkPublishJob: handle() called', [
            'unique_for' => $this->uniqueFor(),
            'job_id' => $this->job?->uuid() ?? 'unknown',
        ]);

        // Additional lock check for extra safety
        if (! $this->acquireLock()) {
            Log::info('BulkPublishJob: Another instance is running, skipping');

            return;
        }

        Log::info('BulkPublishJob: Lock acquired successfully');

        try {
            $this->processPendingPublishes();
        } finally {
            $this->releaseLock();
            Log::info('BulkPublishJob: Lock released');
        }
    }

    private function processPendingPublishes(): void
    {
        Log::info('BulkPublishJob: Starting bulk publish processing');

        // Process by publisher type to use their specific settings
        $publisherTypes = Publish::pendingOrDeferred()
            ->with('publisher')
            ->get()
            ->groupBy('publisher_id');

        Log::info('BulkPublishJob: Queried pending publishes', [
            'total_records' => $publisherTypes->flatten()->count(),
            'publisher_count' => $publisherTypes->count(),
        ]);

        if ($publisherTypes->isEmpty()) {
            Log::info('BulkPublishJob: No pending publishes found');

            return;
        }

        foreach ($publisherTypes as $publisherId => $publishGroup) {
            Log::info('BulkPublishJob: Processing publisher group', [
                'publisher_id' => $publisherId,
                'records_in_group' => $publishGroup->count(),
            ]);

            $this->processPublisherBatch($publishGroup->first()->publisher);
        }
    }

    private function processPublisherBatch(\Ameax\LaravelChangeDetection\Models\Publisher $publisher): void
    {
        Log::info('BulkPublishJob: Starting processPublisherBatch', [
            'publisher' => $publisher->name,
            'publisher_id' => $publisher->id,
        ]);

        $batchSize = $publisher->publisher_class ?
            $this->getPublisherBatchSize($publisher->publisher_class) :
            self::DEFAULT_BATCH_SIZE;

        $delayMs = $publisher->publisher_class ?
            $this->getPublisherDelay($publisher->publisher_class) :
            self::DEFAULT_DELAY_MS;

        Log::info('BulkPublishJob: Publisher settings loaded', [
            'batch_size' => $batchSize,
            'delay_ms' => $delayMs,
        ]);

        // Get publisher instance for error handling
        $publisherInstance = null;
        try {
            if ($publisher->publisher_class && class_exists($publisher->publisher_class)) {
                $publisherInstance = app($publisher->publisher_class);
            }
        } catch (\Exception $e) {
            Log::error('BulkPublishJob: Could not instantiate publisher', [
                'publisher' => $publisher->name,
                'class' => $publisher->publisher_class,
                'error' => $e->getMessage(),
            ]);

            return;
        }

        $publishRecords = Publish::with(['hash.hashable', 'publisher'])
            ->pendingOrDeferred()
            ->where('publisher_id', $publisher->id)
            ->orderBy('created_at')
            ->limit($batchSize)
            ->get();

        if ($publishRecords->isEmpty()) {
            return;
        }

        Log::info('BulkPublishJob: Processing {count} records for {publisher}', [
            'count' => $publishRecords->count(),
            'publisher' => $publisher->name,
            'batch_size' => $batchSize,
            'delay_ms' => $delayMs,
        ]);

        $successCount = 0;
        $failedCount = 0;
        $deferredCount = 0;
        $validationErrors = 0;
        $infrastructureErrors = 0;

        $maxValidationErrors = $publisherInstance ? $publisherInstance->getMaxValidationErrors() : 100;
        $maxInfrastructureErrors = $publisherInstance ? $publisherInstance->getMaxInfrastructureErrors() : 1;

        foreach ($publishRecords as $publishRecord) {
            try {
                $result = $this->processPublishRecord($publishRecord, $publisherInstance);

                switch ($result['status']) {
                    case 'success':
                        $successCount++;
                        break;
                    case 'failed':
                        $failedCount++;
                        break;
                    case 'deferred':
                        $deferredCount++;
                        break;
                    case 'stop_job':
                        Log::warning('BulkPublishJob: Stopping job due to critical error', [
                            'publisher' => $publisher->name,
                            'reason' => $result['reason'] ?? 'Unknown',
                        ]);

                        return;
                }

                // Track error types for job stopping logic
                if (isset($result['error_type'])) {
                    switch ($result['error_type']) {
                        case 'validation':
                            $validationErrors++;
                            break;
                        case 'infrastructure':
                            $infrastructureErrors++;
                            break;
                    }

                    // Stop job if too many errors of specific type
                    if ($maxValidationErrors > 0 && $validationErrors >= $maxValidationErrors) {
                        Log::warning('BulkPublishJob: Stopping job due to too many validation errors', [
                            'publisher' => $publisher->name,
                            'validation_errors' => $validationErrors,
                            'max_allowed' => $maxValidationErrors,
                        ]);

                        return;
                    }

                    if ($maxInfrastructureErrors > 0 && $infrastructureErrors >= $maxInfrastructureErrors) {
                        Log::warning('BulkPublishJob: Stopping job due to infrastructure errors', [
                            'publisher' => $publisher->name,
                            'infrastructure_errors' => $infrastructureErrors,
                            'max_allowed' => $maxInfrastructureErrors,
                        ]);

                        return;
                    }
                }

                // Apply publisher-specific delay
                if ($delayMs > 0) {
                    usleep($delayMs * 1000); // Convert ms to microseconds
                }

            } catch (\Exception $e) {
                Log::error('BulkPublishJob: Unexpected exception processing publish record', [
                    'publish_id' => $publishRecord->id,
                    'error' => $e->getMessage(),
                ]);
                $failedCount++;
                $infrastructureErrors++;

                // Stop on unexpected exceptions if limit reached
                if ($maxInfrastructureErrors > 0 && $infrastructureErrors >= $maxInfrastructureErrors) {
                    Log::error('BulkPublishJob: Stopping job due to unexpected exceptions', [
                        'publisher' => $publisher->name,
                        'infrastructure_errors' => $infrastructureErrors,
                    ]);

                    return;
                }
            }
        }

        Log::info('BulkPublishJob: Completed batch for {publisher}', [
            'publisher' => $publisher->name,
            'total_processed' => $publishRecords->count(),
            'successful' => $successCount,
            'failed' => $failedCount,
            'deferred' => $deferredCount,
            'validation_errors' => $validationErrors,
            'infrastructure_errors' => $infrastructureErrors,
        ]);

        // Dispatch next job if there are more records overall
        $this->dispatchNextBatchIfNeeded();
    }

    private function getPublisherBatchSize(string $publisherClass): int
    {
        try {
            if (! class_exists($publisherClass)) {
                return self::DEFAULT_BATCH_SIZE;
            }

            $publisher = app($publisherClass);

            return $publisher->getBatchSize() ?: self::DEFAULT_BATCH_SIZE;
        } catch (\Exception $e) {
            Log::warning('BulkPublishJob: Could not get batch size for {class}', [
                'class' => $publisherClass,
                'error' => $e->getMessage(),
            ]);

            return self::DEFAULT_BATCH_SIZE;
        }
    }

    private function getPublisherDelay(string $publisherClass): int
    {
        try {
            if (! class_exists($publisherClass)) {
                return self::DEFAULT_DELAY_MS;
            }

            $publisher = app($publisherClass);

            return $publisher->getDelayMs();
        } catch (\Exception $e) {
            Log::warning('BulkPublishJob: Could not get delay for {class}', [
                'class' => $publisherClass,
                'error' => $e->getMessage(),
            ]);

            return self::DEFAULT_DELAY_MS;
        }
    }

    /**
     * @return array{status: string, error_type?: string, reason?: string}
     */
    private function processPublishRecord(Publish $publishRecord, ?\Ameax\LaravelChangeDetection\Contracts\Publisher $publisherInstance = null): array
    {
        if (! $publishRecord->hash) {
            Log::warning('BulkPublishJob: Publish record has no hash', [
                'publish_id' => $publishRecord->id,
            ]);
            $publishRecord->markAsFailed('No hash found', null, 'data');

            return ['status' => 'failed', 'error_type' => 'data'];
        }

        // Increment attempts and mark as dispatched
        $publishRecord->update([
            'status' => 'dispatched',
            'attempts' => $publishRecord->attempts + 1,
        ]);

        try {
            $publisherClass = $publishRecord->publisher->publisher_class;

            if (! class_exists($publisherClass)) {
                throw new \Exception("Publisher class {$publisherClass} not found");
            }

            $publisher = $publisherInstance ?: app($publisherClass);
            $hashableModel = $publishRecord->hash->hashable;

            /** @phpstan-ignore-next-line */
            if (! $hashableModel) {
                throw new \Exception('Hashable model not found');
            }

            if (! $publisher->shouldPublish($hashableModel)) {
                Log::info('BulkPublishJob: Publisher says model should not be published', [
                    'publish_id' => $publishRecord->id,
                    'model_class' => get_class($hashableModel),
                    'model_id' => $hashableModel->getKey(),
                ]);
                $publishRecord->markAsPublished();

                return ['status' => 'success'];
            }

            $data = $publisher->getData($hashableModel);
            $success = $publisher->publish($hashableModel, $data);

            if ($success) {
                $publishRecord->markAsPublished();
                Log::debug('BulkPublishJob: Successfully published', [
                    'publish_id' => $publishRecord->id,
                ]);

                return ['status' => 'success'];
            } else {
                $publishRecord->markAsDeferred('Publisher returned false', null, 'data');

                return ['status' => 'deferred', 'error_type' => 'data'];
            }

        } catch (\Exception $e) {
            Log::error('BulkPublishJob: Failed to publish record', [
                'publish_id' => $publishRecord->id,
                'error' => $e->getMessage(),
            ]);

            // Use publisher to determine error handling strategy
            if ($publisherInstance) {
                $errorHandling = $publisherInstance->handlePublishException($e);
                $errorType = $this->determineErrorType($e);

                // Extract response code if available
                $responseCode = $this->extractResponseCode($e);

                $publishRecord->markAsDeferred($e->getMessage(), $responseCode, $errorType);

                if ($errorHandling === 'stop_job') {
                    return [
                        'status' => 'stop_job',
                        'reason' => $e->getMessage(),
                        'error_type' => $errorType,
                    ];
                } elseif ($errorHandling === 'fail_record') {
                    $publishRecord->markAsFailed($e->getMessage(), $responseCode, $errorType);

                    return ['status' => 'failed', 'error_type' => $errorType];
                }

                return ['status' => 'deferred', 'error_type' => $errorType];
            } else {
                // Fallback without publisher
                $publishRecord->markAsDeferred($e->getMessage(), null, 'unknown');

                return ['status' => 'deferred', 'error_type' => 'unknown'];
            }
        }
    }

    private function determineErrorType(\Exception $e): string
    {
        $message = $e->getMessage();

        // Infrastructure errors
        if (str_contains($message, 'Connection') ||
            str_contains($message, 'timeout') ||
            str_contains($message, 'Permission denied') ||
            str_contains($message, 'Unable to create configured logger') ||
            str_contains($message, 'Network') ||
            str_contains($message, 'SSL') ||
            str_contains($message, 'Authentication failed')) {
            return 'infrastructure';
        }

        // Validation errors
        if (str_contains($message, 'validation') ||
            str_contains($message, 'invalid') ||
            str_contains($message, 'required') ||
            str_contains($message, 'format')) {
            return 'validation';
        }

        // Data errors
        if (str_contains($message, 'not found') ||
            str_contains($message, 'missing') ||
            str_contains($message, 'empty')) {
            return 'data';
        }

        return 'unknown';
    }

    private function extractResponseCode(\Exception $e): ?int
    {
        // Try to extract HTTP response code from exception
        $message = $e->getMessage();

        if (preg_match('/HTTP\/\d\.\d (\d{3})/', $message, $matches)) {
            return (int) $matches[1];
        }

        if (preg_match('/status code:? (\d{3})/i', $message, $matches)) {
            return (int) $matches[1];
        }

        if (preg_match('/(\d{3}) [A-Z]/', $message, $matches)) {
            return (int) $matches[1];
        }

        return null;
    }

    private function dispatchNextBatchIfNeeded(): void
    {
        // Check if there are more pending records
        $remainingCount = Publish::pendingOrDeferred()->count();

        Log::info('BulkPublishJob: Checking for next batch', [
            'remaining_count' => $remainingCount,
        ]);

        if ($remainingCount > 0) {
            Log::info('BulkPublishJob: {count} records remaining, dispatching next batch', [
                'count' => $remainingCount,
            ]);

            // Dispatch next job with configurable delay to prevent overwhelming server
            $delaySeconds = config('change-detection.job_dispatch_delay', 10);
            $job = self::dispatch()->delay(now()->addSeconds($delaySeconds));

            Log::info('BulkPublishJob: Next batch dispatched', [
                'delay_seconds' => $delaySeconds,
                'delayed_until' => now()->addSeconds($delaySeconds)->toDateTimeString(),
            ]);
        } else {
            Log::info('BulkPublishJob: All pending publishes processed');
        }
    }

    private function acquireLock(): bool
    {
        $lockTimeout = config('change-detection.job_timeout', 1800);

        return Cache::lock(self::LOCK_KEY, $lockTimeout)->get();
    }

    private function releaseLock(): void
    {
        Cache::lock(self::LOCK_KEY)->release();
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('BulkPublishJob: Job failed permanently', [
            'error' => $exception->getMessage(),
            'attempts' => $this->attempts(),
        ]);

        $this->releaseLock();
    }
}
