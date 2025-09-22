<?php

declare(strict_types=1);

namespace Ameax\LaravelChangeDetection\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class TruncateCommand extends Command
{
    public $signature = 'change-detection:truncate
                        {--force : Skip confirmation prompt}
                        {--only= : Only truncate specific tables (comma-separated: hashes,hash_dependents,publishes)}';

    public $description = 'Truncate all change detection tables (hashes, hash_dependents, publishes)';

    public function handle(): int
    {
        $connection = config('change-detection.database_connection');
        $db = DB::connection($connection);

        // Get table names from config
        $hashesTable = config('change-detection.tables.hashes', 'hashes');
        $hashDependentsTable = config('change-detection.tables.hash_dependents', 'hash_dependents');
        $publishesTable = config('change-detection.tables.publishes', 'publishes');

        // Determine which tables to truncate
        $tablesToTruncate = $this->getTablesToTruncate($hashesTable, $hashDependentsTable, $publishesTable);

        if (empty($tablesToTruncate)) {
            $this->error('No valid tables specified for truncation.');

            return self::FAILURE;
        }

        // Count records before truncation
        $recordCounts = $this->getRecordCounts($db, $tablesToTruncate);
        $totalRecords = array_sum($recordCounts);

        if ($totalRecords === 0) {
            $this->info('All specified tables are already empty.');

            return self::SUCCESS;
        }

        // Display what will be truncated
        $this->displayRecordCounts($recordCounts);

        // Confirm truncation if not forced
        if (! $this->option('force')) {
            $message = sprintf(
                'Are you sure you want to truncate %d tables with a total of %d records?',
                count($tablesToTruncate),
                $totalRecords
            );

            if (! $this->confirm($message)) {
                $this->info('Truncation cancelled.');

                return self::SUCCESS;
            }
        }

        // Perform truncation
        $this->line('');
        $this->info('Truncating tables...');

        try {
            // Disable foreign key checks temporarily
            $db->statement('SET FOREIGN_KEY_CHECKS = 0');

            foreach ($tablesToTruncate as $table) {
                if (Schema::connection($connection)->hasTable($table)) {
                    $db->table($table)->truncate();
                    $this->info("  ✓ Truncated table: {$table}");
                } else {
                    $this->warn("  ✗ Table not found: {$table}");
                }
            }

            // Re-enable foreign key checks
            $db->statement('SET FOREIGN_KEY_CHECKS = 1');

            $this->line('');
            $this->info('Successfully truncated all specified tables.');

            // Show warning about needing to rebuild hashes
            $this->line('');
            $this->warn('⚠️  Important: All hash data has been removed!');
            $this->info('Run "php artisan change-detection:detect --auto-discover --update" to rebuild hashes.');

            return self::SUCCESS;

        } catch (\Exception $e) {
            // Re-enable foreign key checks in case of error
            $db->statement('SET FOREIGN_KEY_CHECKS = 1');

            $this->error('Failed to truncate tables: '.$e->getMessage());

            return self::FAILURE;
        }
    }

    /**
     * Determine which tables to truncate based on options.
     */
    private function getTablesToTruncate(string $hashesTable, string $hashDependentsTable, string $publishesTable): array
    {
        $only = $this->option('only');

        if ($only) {
            $requested = array_map('trim', explode(',', $only));
            $tables = [];

            foreach ($requested as $table) {
                switch ($table) {
                    case 'hashes':
                        $tables[] = $hashesTable;
                        break;
                    case 'hash_dependents':
                        $tables[] = $hashDependentsTable;
                        break;
                    case 'publishes':
                        $tables[] = $publishesTable;
                        break;
                    default:
                        $this->warn("Unknown table alias: {$table}");
                }
            }

            return array_unique($tables);
        }

        // Default: truncate all tables
        return [$hashesTable, $hashDependentsTable, $publishesTable];
    }

    /**
     * Get record counts for each table.
     */
    private function getRecordCounts($db, array $tables): array
    {
        $counts = [];
        $connection = config('change-detection.database_connection');

        foreach ($tables as $table) {
            if (Schema::connection($connection)->hasTable($table)) {
                $counts[$table] = $db->table($table)->count();
            } else {
                $counts[$table] = 0;
            }
        }

        return $counts;
    }

    /**
     * Display record counts in a table format.
     */
    private function displayRecordCounts(array $recordCounts): void
    {
        $this->line('');
        $this->info('=== Tables to be Truncated ===');

        $tableData = [];
        foreach ($recordCounts as $table => $count) {
            $tableData[] = [
                'Table' => $table,
                'Records' => number_format($count),
            ];
        }

        $this->table(['Table', 'Records'], $tableData);

        $total = array_sum($recordCounts);
        $this->line('Total records to be deleted: '.number_format($total));
    }
}
