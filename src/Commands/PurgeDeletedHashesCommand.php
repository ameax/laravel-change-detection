<?php

declare(strict_types=1);

namespace Ameax\LaravelChangeDetection\Commands;

use Ameax\LaravelChangeDetection\Services\HashPurger;
use Illuminate\Console\Command;

class PurgeDeletedHashesCommand extends Command
{
    public $signature = 'change-detection:purge
                        {--older-than= : Only purge hashes deleted more than X days ago}
                        {--dry-run : Show what would be purged without actually deleting}
                        {--force : Skip confirmation prompt}';

    public $description = 'Purge deleted hash records from the database';

    public function handle(): int
    {
        $purger = app(HashPurger::class);
        $olderThanDays = $this->option('older-than') ? (int) $this->option('older-than') : null;
        $dryRun = $this->option('dry-run');
        $force = $this->option('force');

        // Count purgeable records
        $purgeableCount = $purger->countPurgeable($olderThanDays);

        if ($purgeableCount === 0) {
            $this->info('No deleted hashes found to purge.');
            return self::SUCCESS;
        }

        // Get statistics
        $statistics = $purger->getPurgeableStatistics($olderThanDays);

        // Display what will be purged
        $this->displayStatistics($statistics, $olderThanDays);

        if ($dryRun) {
            $this->line('');
            $this->warn('Dry run mode - no records were actually deleted.');
            return self::SUCCESS;
        }

        // Confirm deletion if not forced
        if (!$force) {
            $message = $olderThanDays
                ? "Are you sure you want to purge {$purgeableCount} hash records deleted more than {$olderThanDays} days ago?"
                : "Are you sure you want to purge ALL {$purgeableCount} deleted hash records?";

            if (!$this->confirm($message)) {
                $this->info('Purge cancelled.');
                return self::SUCCESS;
            }
        }

        // Perform the purge
        $this->line('');
        $this->info('Purging deleted hashes...');

        $purgedCount = $purger->purgeDeletedHashes($olderThanDays);

        $this->info("Successfully purged {$purgedCount} hash records.");

        // Note about cascade deletes
        if ($purgedCount > 0) {
            $this->line('');
            $this->info('Note: Related records in publishes and hash_dependents tables were automatically deleted via cascade.');
        }

        return self::SUCCESS;
    }

    /**
     * Display statistics about what will be purged.
     *
     * @param array<int, array{model_type: string, count: int, oldest_deleted_at: string}> $statistics
     * @param int|null $olderThanDays
     */
    private function displayStatistics(array $statistics, ?int $olderThanDays): void
    {
        $this->line('');

        if ($olderThanDays) {
            $this->info("=== Deleted Hashes Older Than {$olderThanDays} Days ===");
        } else {
            $this->info('=== All Deleted Hashes ===');
        }

        $table = array_map(function ($stat) {
            return [
                'Model Type' => $stat['model_type'],
                'Count' => $stat['count'],
                'Oldest Deleted' => $stat['oldest_deleted_at'],
            ];
        }, $statistics);

        $this->table(['Model Type', 'Count', 'Oldest Deleted'], $table);

        $total = array_sum(array_column($statistics, 'count'));
        $this->line("Total records to purge: {$total}");
    }
}