<?php

declare(strict_types=1);

namespace Ameax\LaravelChangeDetection\Commands;

use Ameax\LaravelChangeDetection\Jobs\BulkPublishJob;
use Ameax\LaravelChangeDetection\Models\Publish;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class ProcessPublishesCommand extends Command
{
    protected $signature = 'change-detection:process-publishes
                           {--force : Force processing even if another job is running}
                           {--sync : Process synchronously instead of dispatching job}';

    protected $description = 'Process pending publish records';

    public function handle(): int
    {
        $pendingCount = Publish::pendingOrDeferred()->count();

        if ($pendingCount === 0) {
            $this->info('No pending publishes found.');

            return self::SUCCESS;
        }

        $this->info("Found {$pendingCount} pending publish records.");

        if ($this->option('sync')) {
            return $this->processSynchronously();
        }

        if ($this->option('force')) {
            Cache::forget('bulk_publish_job_running');
            $this->warn('Forced clearing of job lock.');
        }

        // Check if job is already running
        if (Cache::has('bulk_publish_job_running')) {
            $this->warn('BulkPublishJob is already running. Use --force to override or --sync to process synchronously.');

            return self::FAILURE;
        }

        $this->info('Dispatching BulkPublishJob...');
        BulkPublishJob::dispatch();

        $this->info('BulkPublishJob dispatched successfully.');

        return self::SUCCESS;
    }

    private function processSynchronously(): int
    {
        $this->info('Processing publishes synchronously...');

        $processed = 0;
        $success = 0;
        $failed = 0;

        $publishes = Publish::with(['hash', 'publisher'])
            ->pendingOrDeferred()
            ->orderBy('created_at')
            ->limit(100) // Smaller batch for sync processing
            ->get();

        $progressBar = $this->output->createProgressBar($publishes->count());
        $progressBar->start();

        foreach ($publishes as $publish) {
            $result = $publish->publishNow();

            if ($result) {
                $success++;
            } else {
                $failed++;
            }

            $processed++;
            $progressBar->advance();
        }

        $progressBar->finish();
        $this->newLine();

        $this->table(['Metric', 'Count'], [
            ['Processed', $processed],
            ['Successful', $success],
            ['Failed/Deferred', $failed],
        ]);

        return self::SUCCESS;
    }
}
