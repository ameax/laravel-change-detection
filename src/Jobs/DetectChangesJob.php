<?php

declare(strict_types=1);

namespace Ameax\LaravelChangeDetection\Jobs;

use Ameax\LaravelChangeDetection\Services\BulkHashProcessor;
use Ameax\LaravelChangeDetection\Services\ChangeDetector;
use Ameax\LaravelChangeDetection\Services\OrphanedHashDetector;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class DetectChangesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300; // 5 minutes

    public int $tries = 3;

    /** @var class-string */
    private string $modelClass;

    private bool $updateHashes;

    private bool $cleanupOrphaned;

    private int $limit;

    /**
     * @param  class-string  $modelClass
     */
    public function __construct(
        string $modelClass,
        bool $updateHashes = false,
        bool $cleanupOrphaned = false,
        int $limit = 1000
    ) {
        $this->modelClass = $modelClass;
        $this->updateHashes = $updateHashes;
        $this->cleanupOrphaned = $cleanupOrphaned;
        $this->limit = $limit;

        $this->onQueue(config('change-detection.queues.detect_changes', 'default'));
    }

    public function handle(): void
    {
        $detector = app(ChangeDetector::class);

        Log::info("Starting change detection for {$this->modelClass}", [
            'model_class' => $this->modelClass,
            'update_hashes' => $this->updateHashes,
            'cleanup_orphaned' => $this->cleanupOrphaned,
            'limit' => $this->limit,
        ]);

        try {
            $changedCount = $detector->countChangedModels($this->modelClass);

            Log::info("Found {$changedCount} changed records for {$this->modelClass}");

            if ($this->updateHashes && $changedCount > 0) {
                $this->updateChangedHashes($changedCount);
            }

            if ($this->cleanupOrphaned) {
                $this->cleanupOrphanedHashes();
            }

            Log::info("Completed change detection for {$this->modelClass}", [
                'changed_count' => $changedCount,
                'updated' => $this->updateHashes,
                'cleaned_up' => $this->cleanupOrphaned,
            ]);

        } catch (\Exception $e) {
            Log::error("Failed to process change detection for {$this->modelClass}", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw $e;
        }
    }

    private function updateChangedHashes(int $changedCount): void
    {
        $processor = app(BulkHashProcessor::class);

        Log::info("Updating {$changedCount} changed hashes for {$this->modelClass}");

        $updated = $processor->processChangedModels($this->modelClass, $this->limit);

        Log::info("Updated {$updated} hash records for {$this->modelClass}");
    }

    private function cleanupOrphanedHashes(): void
    {
        $detector = app(OrphanedHashDetector::class);

        Log::info("Cleaning up orphaned hashes for {$this->modelClass}");

        $orphanedCount = $detector->countOrphanedHashes($this->modelClass);

        if ($orphanedCount > 0) {
            $cleaned = $detector->cleanupOrphanedHashes($this->modelClass, $this->limit);
            Log::info("Cleaned up {$cleaned} orphaned hashes for {$this->modelClass}");
        } else {
            Log::info("No orphaned hashes found for {$this->modelClass}");
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error("DetectChangesJob failed permanently for {$this->modelClass}", [
            'error' => $exception->getMessage(),
            'attempts' => $this->attempts(),
        ]);
    }

    public function getModelClass(): string
    {
        return $this->modelClass;
    }

    public function shouldUpdateHashes(): bool
    {
        return $this->updateHashes;
    }

    public function shouldCleanupOrphaned(): bool
    {
        return $this->cleanupOrphaned;
    }

    public function getLimit(): int
    {
        return $this->limit;
    }
}
