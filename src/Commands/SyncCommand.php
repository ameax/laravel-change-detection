<?php

declare(strict_types=1);

namespace Ameax\LaravelChangeDetection\Commands;

use Ameax\LaravelChangeDetection\Contracts\Hashable;
use Ameax\LaravelChangeDetection\Helpers\ModelDiscoveryHelper;
use Ameax\LaravelChangeDetection\Models\Publisher;
use Ameax\LaravelChangeDetection\Services\BulkHashProcessor;
use Ameax\LaravelChangeDetection\Services\BulkPublishProcessor;
use Ameax\LaravelChangeDetection\Services\ChangeDetector;
use Ameax\LaravelChangeDetection\Services\OrphanedHashDetector;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\File;
use ReflectionClass;

class SyncCommand extends Command
{
    public $signature = 'change-detection:sync
                        {--limit= : Limit number of records to process per model}
                        {--purge : Hard delete orphaned and soft-deleted hashes from database}
                        {--report : Show detailed report of all operations}
                        {--models=* : Specific model classes to sync (defaults to auto-discovery)}';

    public $description = 'Synchronize all hash records (auto-discover, detect, cleanup, and update)';

    private int $totalChangesDetected = 0;

    private int $totalHashesUpdated = 0;

    private int $totalOrphansProcessed = 0;

    /** @var array<string, array{name: string, changes_detected: int, hashes_updated: int, orphans_processed: int}> */
    private array $modelStats = [];

    public function handle(): int
    {
        $startTime = microtime(true);

        $this->info('🔄 Starting hash synchronization...');
        $this->line('');

        // Get models to process
        $models = $this->getTargetModels();

        if ($models->isEmpty()) {
            $this->error('No hashable models found.');

            return self::FAILURE;
        }

        $this->info("Found {$models->count()} hashable model(s) to process");
        $this->line('');

        // Step 1: Detect changes
        $this->detectChanges($models);

        // Step 2: Clean up orphaned hashes
        $this->cleanupOrphans($models);

        // Step 3: Update changed hashes
        if ($this->totalChangesDetected > 0) {
            $this->updateHashes($models);
        }

        // Step 4: Sync publish records for all models
        $this->syncPublishRecords($models);

        // Show summary
        $this->showSummary($startTime);

        if ($this->option('report')) {
            $this->showDetailedReport();
        }

        return self::SUCCESS;
    }

    /**
     * Get target models either from options or auto-discovery.
     *
     * @return \Illuminate\Support\Collection<int, class-string>
     */
    private function getTargetModels(): \Illuminate\Support\Collection
    {
        $specifiedModels = $this->option('models');

        if (! empty($specifiedModels) && is_array($specifiedModels)) {
            return collect($specifiedModels)->filter(function ($model) {
                if (! class_exists($model)) {
                    $this->error("Model class {$model} does not exist");

                    return false;
                }

                return $this->implementsHashable($model);
            });
        }

        // Auto-discover models
        return $this->discoverHashableModels();
    }

    /**
     * Detect changes across all models.
     *
     * @param  \Illuminate\Support\Collection<int, class-string>  $models
     */
    private function detectChanges(\Illuminate\Support\Collection $models): void
    {
        $this->info('📊 Detecting changes...');

        $detector = app(ChangeDetector::class);
        $limit = $this->option('limit');

        foreach ($models as $modelClass) {
            $modelName = class_basename($modelClass);
            $this->line("  Checking {$modelName}...");

            /** @var class-string<\Illuminate\Database\Eloquent\Model&\Ameax\LaravelChangeDetection\Contracts\Hashable> $modelClass */
            $changedCount = $limit
                ? $detector->countChangedModels($modelClass, (int) $limit)
                : $detector->countChangedModels($modelClass);

            $this->modelStats[$modelClass] = [
                'name' => $modelName,
                'changes_detected' => $changedCount,
                'hashes_updated' => 0,
                'orphans_processed' => 0,
            ];

            $this->totalChangesDetected += $changedCount;

            if ($changedCount > 0) {
                $this->warn("    → {$changedCount} changes detected");
            } else {
                $this->info('    → No changes detected');
            }
        }

        $this->line('');
    }

    /**
     * Clean up orphaned hashes.
     *
     * @param  \Illuminate\Support\Collection<int, class-string>  $models
     */
    private function cleanupOrphans(\Illuminate\Support\Collection $models): void
    {
        $this->info('🧹 Cleaning up orphaned hashes...');

        $orphanDetector = app(OrphanedHashDetector::class);
        $isPurge = $this->option('purge');
        $action = $isPurge ? 'purged' : 'cleaned';

        foreach ($models as $modelClass) {
            /** @var class-string<\Illuminate\Database\Eloquent\Model&\Ameax\LaravelChangeDetection\Contracts\Hashable> $modelClass */
            $modelName = class_basename($modelClass);

            if ($isPurge) {
                $cleaned = $orphanDetector->purgeOrphanedHashes($modelClass);
            } else {
                $cleaned = $orphanDetector->cleanupOrphanedHashes($modelClass);
            }

            if ($cleaned > 0) {
                $this->modelStats[$modelClass]['orphans_processed'] = $cleaned;
                $this->totalOrphansProcessed += $cleaned;
                $this->warn("  {$modelName}: {$action} {$cleaned} orphaned hashes");
            }
        }

        if ($this->totalOrphansProcessed === 0) {
            $this->info('  No orphaned hashes found');
        } elseif ($isPurge) {
            $this->line('');
            $this->info('Note: Related records in publishes and hash_dependents tables were automatically deleted via cascade.');
        }

        $this->line('');
    }

    /**
     * Update changed hashes.
     *
     * @param  \Illuminate\Support\Collection<int, class-string>  $models
     */
    private function updateHashes(\Illuminate\Support\Collection $models): void
    {
        $this->info('✅ Updating changed hashes...');

        $processor = app(BulkHashProcessor::class);
        $limit = $this->option('limit');

        foreach ($models as $modelClass) {
            $modelName = class_basename($modelClass);

            if ($this->modelStats[$modelClass]['changes_detected'] > 0) {
                $this->line("  Processing {$modelName}...");

                /** @var class-string<\Illuminate\Database\Eloquent\Model&\Ameax\LaravelChangeDetection\Contracts\Hashable> $modelClass */
                $updated = $limit
                    ? $processor->processChangedModels($modelClass, (int) $limit)
                    : $processor->processChangedModels($modelClass);

                $this->modelStats[$modelClass]['hashes_updated'] = $updated;
                $this->totalHashesUpdated += $updated;

                if ($updated > 0) {
                    $this->info("    → Updated {$updated} hash records");
                }
            }

            // Always check for pending dependencies (even for models with no changes)
            /** @var class-string<\Illuminate\Database\Eloquent\Model&\Ameax\LaravelChangeDetection\Contracts\Hashable> $modelClass */
            $pendingDeps = $limit
                ? $processor->buildPendingDependencies($modelClass, (int) $limit)
                : $processor->buildPendingDependencies($modelClass);

            if ($pendingDeps > 0) {
                $this->line("  {$modelName}: Building dependencies...");
                $this->info("    → Built dependencies for {$pendingDeps} records");
            }
        }

        $this->line('');
    }

    /**
     * Sync publish records for all models.
     *
     * @param  \Illuminate\Support\Collection<int, class-string>  $models
     */
    private function syncPublishRecords(\Illuminate\Support\Collection $models): void
    {
        $this->info('📤 Syncing publish records...');

        $publishProcessor = app(BulkPublishProcessor::class);
        $totalCreated = 0;
        $totalUpdated = 0;

        foreach ($models as $modelClass) {
            $modelName = class_basename($modelClass);
            $this->line("  Processing {$modelName}...");

            /** @var class-string<\Illuminate\Database\Eloquent\Model&\Ameax\LaravelChangeDetection\Contracts\Hashable> $modelClass */
            $result = $publishProcessor->syncAllPublishRecords($modelClass);

            if ($result['created'] > 0 || $result['updated'] > 0) {
                if ($result['created'] > 0) {
                    $this->info("    → Created {$result['created']} publish records");
                    $totalCreated += $result['created'];
                }
                if ($result['updated'] > 0) {
                    $this->info("    → Updated {$result['updated']} publish records");
                    $totalUpdated += $result['updated'];
                }
            } else {
                $this->line('    → No publish changes needed');
            }
        }

        if ($totalCreated > 0 || $totalUpdated > 0) {
            $this->line('');
            $this->info("Total: Created {$totalCreated}, Updated {$totalUpdated} publish records");
        }

        $this->line('');
    }

    /**
     * Show summary of operations.
     */
    private function showSummary(float $startTime): void
    {
        $duration = round(microtime(true) - $startTime, 2);

        $this->info('═══════════════════════════════════════════');
        $this->info('📈 Synchronization Summary');
        $this->info('═══════════════════════════════════════════');

        $this->line('Models processed: '.count($this->modelStats));
        $this->line("Changes detected: {$this->totalChangesDetected}");
        $this->line("Hashes updated: {$this->totalHashesUpdated}");
        $action = $this->option('purge') ? 'purged' : 'marked as deleted';
        $this->line("Orphaned hashes {$action}: {$this->totalOrphansProcessed}");
        $this->line("Execution time: {$duration} seconds");

        $this->line('');

        if ($this->totalChangesDetected === 0 && $this->totalOrphansProcessed === 0) {
            $this->info('✨ All hash records are up to date!');
        } else {
            $this->info('✅ Synchronization completed successfully!');
        }
    }

    /**
     * Show detailed report of all operations.
     */
    private function showDetailedReport(): void
    {
        if (empty($this->modelStats)) {
            return;
        }

        $this->line('');
        $this->info('═══════════════════════════════════════════');
        $this->info('📋 Detailed Report');
        $this->info('═══════════════════════════════════════════');

        $tableData = [];
        foreach ($this->modelStats as $modelClass => $stats) {
            $tableData[] = [
                'Model' => $stats['name'],
                'Changes Detected' => $stats['changes_detected'],
                'Hashes Updated' => $stats['hashes_updated'],
                'Orphans Processed' => $stats['orphans_processed'],
            ];
        }

        $this->table(
            ['Model', 'Changes Detected', 'Hashes Updated', 'Orphans Processed'],
            $tableData
        );
    }

    /**
     * Check if a model implements the Hashable interface.
     */
    private function implementsHashable(string $modelClass): bool
    {
        return ModelDiscoveryHelper::isHashable($modelClass);
    }

    /**
     * Auto-discover all hashable models in the application.
     *
     * @return \Illuminate\Support\Collection<int, class-string>
     */
    private function discoverHashableModels(): \Illuminate\Support\Collection
    {
        $models = collect();

        // First, discover models from Publishers
        $this->discoverModelsFromPublishers($models);

        // Then, discover models from app/Models as fallback
        $this->discoverModelsFromAppPath($models);

        return $models->unique()->sort()->values();
    }

    /**
     * Discover models from Publisher records.
     */
    private function discoverModelsFromPublishers(\Illuminate\Support\Collection &$models): void
    {
        $publishers = Publisher::active()->get();

        foreach ($publishers as $publisher) {
            $modelType = $publisher->model_type;

            // Get all models needed for this publisher (main + dependencies)
            $publisherModels = ModelDiscoveryHelper::getAllModelsForSync($modelType);

            foreach ($publisherModels as $modelClass) {
                if (ModelDiscoveryHelper::isHashable($modelClass)) {
                    $models->push($modelClass);
                }
            }
        }
    }

    /**
     * Discover models from app/Models directory.
     */
    private function discoverModelsFromAppPath(\Illuminate\Support\Collection &$models): void
    {
        $appPath = app_path('Models');

        if (! File::exists($appPath)) {
            return;
        }

        $files = File::allFiles($appPath);

        foreach ($files as $file) {
            $relativePath = str_replace($appPath.DIRECTORY_SEPARATOR, '', $file->getPathname());
            $className = 'App\\Models\\'.str_replace(['/', '.php'], ['\\', ''], $relativePath);

            if (class_exists($className)) {
                $reflection = new ReflectionClass($className);

                if (! $reflection->isAbstract() &&
                    $reflection->isSubclassOf(Model::class) &&
                    $reflection->implementsInterface(Hashable::class)) {
                    $models->push($className);
                }
            }
        }
    }
}
