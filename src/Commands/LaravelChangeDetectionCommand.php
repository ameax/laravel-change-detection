<?php

namespace Ameax\LaravelChangeDetection\Commands;

use Ameax\LaravelChangeDetection\Contracts\Hashable;
use Ameax\LaravelChangeDetection\Services\BulkHashProcessor;
use Ameax\LaravelChangeDetection\Services\ChangeDetector;
use Ameax\LaravelChangeDetection\Services\OrphanedHashDetector;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\File;
use ReflectionClass;

class LaravelChangeDetectionCommand extends Command
{
    public $signature = 'change-detection:detect
                        {--models=* : Specific model classes to check}
                        {--update : Update hashes for detected changes}
                        {--cleanup : Clean up orphaned hashes}
                        {--purge : Immediately purge orphaned hashes instead of marking as deleted}
                        {--limit= : Limit number of records to process (default: no limit)}
                        {--report : Show detailed report}
                        {--auto-discover : Auto-discover hashable models}';

    public $description = 'Detect and optionally fix hash changes across models';

    public function handle(): int
    {
        $models = $this->getTargetModels();

        if ($models->isEmpty()) {
            $this->error('No hashable models found. Use --auto-discover or specify --models.');

            return self::FAILURE;
        }

        $this->info("Checking {$models->count()} model(s) for changes...");

        $detector = app(ChangeDetector::class);
        $results = collect();
        $totalChanges = 0;

        foreach ($models as $modelClass) {
            $this->line("Checking {$modelClass}...");

            $changedCount = $detector->countChangedModels($modelClass);
            $totalChanges += $changedCount;

            $results->push([
                'model' => $modelClass,
                'changed_count' => $changedCount,
            ]);

            if ($changedCount > 0) {
                $this->warn("  Found {$changedCount} changed records");
            } else {
                $this->info('  No changes detected');
            }
        }

        if ($this->option('report')) {
            $this->showDetailedReport($results);
        }

        if ($this->option('cleanup')) {
            $this->cleanupOrphanedHashes($models, $this->option('purge'));
        }

        if ($this->option('update') && $totalChanges > 0) {
            return $this->updateChangedHashes($models);
        }

        if ($totalChanges > 0) {
            $this->line('');
            $this->warn("Total: {$totalChanges} changes detected across all models");
            $this->info('Use --update to fix the detected changes');
        } else {
            $this->info('All hash records are up to date!');
        }

        return self::SUCCESS;
    }

    /**
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

        if ($this->option('auto-discover')) {
            return $this->discoverHashableModels();
        }

        return collect();
    }

    /**
     * @return \Illuminate\Support\Collection<int, class-string>
     */
    private function discoverHashableModels(): \Illuminate\Support\Collection
    {
        // Step 1: Start with models that have publishers (main models)
        $mainModels = $this->getModelsFromPublishers();

        if ($mainModels->isEmpty()) {
            // Fallback to old behavior if no publishers configured
            return $this->discoverAllHashableModels();
        }

        // Step 2: Find all dependency models from main models
        $dependencyModels = $this->findDependencyModels($mainModels);

        // Step 3: Return in correct processing order: dependencies first, then main models
        return $dependencyModels->merge($mainModels)->unique();
    }

    private function getModelsFromPublishers(): \Illuminate\Support\Collection
    {
        $morphClasses = \Ameax\LaravelChangeDetection\Models\Publisher::where('status', 'active')
            ->pluck('model_type')
            ->unique();

        return $morphClasses->map(function ($morphClass) {
            return $this->getModelClassFromMorphClass($morphClass);
        })->filter(function ($modelClass) {
            return $modelClass && class_exists($modelClass) && $this->implementsHashable($modelClass);
        });
    }

    private function findDependencyModels(\Illuminate\Support\Collection $mainModels): \Illuminate\Support\Collection
    {
        $dependencyModels = collect();

        foreach ($mainModels as $modelClass) {
            $model = new $modelClass;
            $dependencies = $model->getHashCompositeDependencies();

            foreach ($dependencies as $relationName) {
                if (method_exists($model, $relationName)) {
                    try {
                        $relation = $model->{$relationName}();
                        $relatedModel = $relation->getRelated();

                        if ($relatedModel instanceof \Ameax\LaravelChangeDetection\Contracts\Hashable) {
                            $dependencyModels->push(get_class($relatedModel));
                        }
                    } catch (\Exception $e) {
                        // Skip invalid relations
                    }
                }
            }
        }

        return $dependencyModels->unique();
    }

    private function discoverAllHashableModels(): \Illuminate\Support\Collection
    {
        $models = collect();
        $appPath = app_path('Models');

        if (! File::exists($appPath)) {
            $appPath = app_path();
        }

        $files = File::allFiles($appPath);

        foreach ($files as $file) {
            $className = $this->getClassNameFromFile($file);

            if ($className && $this->implementsHashable($className)) {
                $models->push($className);
            }
        }

        return $models;
    }

    private function getClassNameFromFile(\SplFileInfo $file): ?string
    {
        $content = File::get($file->getPathname());

        if (preg_match('/namespace\s+([^;]+);/', $content, $namespaceMatches) &&
            preg_match('/class\s+(\w+)/', $content, $classMatches)) {
            return $namespaceMatches[1].'\\'.$classMatches[1];
        }

        return null;
    }

    private function implementsHashable(string $className): bool
    {
        if (! class_exists($className)) {
            return false;
        }

        $reflection = new ReflectionClass($className);

        return $reflection->implementsInterface(Hashable::class) &&
               $reflection->isSubclassOf(Model::class) &&
               ! $reflection->isAbstract();
    }

    /**
     * @param  \Illuminate\Support\Collection<int, array{model: class-string, changed_count: int}>  $results
     */
    private function showDetailedReport(\Illuminate\Support\Collection $results): void
    {
        $this->line('');
        $this->info('=== Detailed Report ===');

        $table = $results->map(function ($result) {
            return [
                'Model' => class_basename($result['model']),
                'Full Class' => $result['model'],
                'Changed Records' => $result['changed_count'],
                'Status' => $result['changed_count'] > 0 ? '⚠️  Needs Update' : '✅ Up to Date',
            ];
        });

        $this->table(['Model', 'Full Class', 'Changed Records', 'Status'], $table->toArray());
    }

    /**
     * @param  \Illuminate\Support\Collection<int, class-string>  $models
     * @param  bool  $purge  Whether to immediately purge instead of just marking as deleted
     */
    private function cleanupOrphanedHashes(\Illuminate\Support\Collection $models, bool $purge = false): void
    {
        $this->line('');

        if ($purge) {
            $this->info('Purging orphaned hashes...');
        } else {
            $this->info('Cleaning up orphaned hashes...');
        }

        $detector = app(OrphanedHashDetector::class);
        $totalCleaned = 0;

        foreach ($models as $modelClass) {
            $cleaned = $purge
                ? $detector->purgeOrphanedHashes($modelClass)
                : $detector->cleanupOrphanedHashes($modelClass);

            if ($cleaned > 0) {
                $action = $purge ? 'purged' : 'cleaned';
                $this->info("  {$modelClass}: {$action} {$cleaned} orphaned hashes");
                $totalCleaned += $cleaned;
            }
        }

        if ($totalCleaned > 0) {
            $action = $purge ? 'purged' : 'cleaned';
            $this->info("Total orphaned hashes {$action}: {$totalCleaned}");

            if ($purge) {
                $this->info('Note: Related records in publishes and hash_dependents tables were automatically deleted via cascade.');
            }
        } else {
            $this->info('No orphaned hashes found');
        }
    }

    /**
     * @param  \Illuminate\Support\Collection<int, class-string>  $models
     */
    private function updateChangedHashes(\Illuminate\Support\Collection $models): int
    {
        $this->line('');
        $this->info('Updating changed hashes...');

        $processor = app(BulkHashProcessor::class);
        $limit = $this->option('limit') ? (int) $this->option('limit') : null;
        $totalUpdated = 0;

        foreach ($models as $modelClass) {
            $this->line("Processing {$modelClass}...");

            $updated = $processor->processChangedModels($modelClass, $limit);
            if ($updated > 0) {
                $this->info("  Updated {$updated} hash records");
                $totalUpdated += $updated;
            }
        }

        $this->info("Total hash records updated: {$totalUpdated}");

        return self::SUCCESS;
    }

    /**
     * Get the model class from a morph class name by discovering all hashable models.
     */
    private function getModelClassFromMorphClass(string $morphClass): ?string
    {
        // Get all hashable models from the application
        $allHashableModels = $this->discoverAllHashableModels();

        foreach ($allHashableModels as $class) {
            try {
                $instance = new $class;
                if ($instance->getMorphClass() === $morphClass) {
                    return $class;
                }
            } catch (\Exception $e) {
                // Skip invalid classes
            }
        }

        return null;
    }
}
