<?php

declare(strict_types=1);

namespace Ameax\LaravelChangeDetection\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;

class SyncHashesJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $maxExceptions = 3;

    public function __construct()
    {
        $this->onQueue(config('change-detection.queues.detect_changes', 'default'));
        $this->timeout = config('change-detection.sync_job_timeout', 3600);
    }

    /**
     * Get unique job identifier to ensure only one instance runs
     */
    public function uniqueId(): string
    {
        return 'sync_hashes_job';
    }

    /**
     * How long to wait before retrying the job if it's unique
     */
    public function uniqueFor(): int
    {
        return config('change-detection.sync_job_unique_for', 120);
    }

    public function handle(): void
    {
        $this->log('info', 'SyncHashesJob: Starting hash synchronization', [
            'unique_for' => $this->uniqueFor(),
            'job_id' => $this->job?->uuid() ?? 'unknown',
        ]);

        $exitCode = Artisan::call('change-detection:sync');

        if ($exitCode === 0) {
            $this->log('info', 'SyncHashesJob: Hash synchronization completed successfully');
        } else {
            $this->log('error', 'SyncHashesJob: Hash synchronization failed', [
                'exit_code' => $exitCode,
            ]);
        }
    }

    public function failed(\Throwable $exception): void
    {
        $this->log('error', 'SyncHashesJob: Job failed permanently', [
            'error' => $exception->getMessage(),
            'attempts' => $this->attempts(),
        ]);
    }

    /**
     * Log to the configured change detection channel
     */
    private function log(string $level, string $message, array $context = []): void
    {
        $channel = config('change-detection.log_channels.change_detection');

        if ($channel) {
            Log::channel($channel)->$level($message, $context);
        } else {
            Log::$level($message, $context);
        }
    }
}
