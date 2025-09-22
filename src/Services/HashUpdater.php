<?php

declare(strict_types=1);

namespace Ameax\LaravelChangeDetection\Services;

use Ameax\LaravelChangeDetection\Contracts\Hashable;
use Ameax\LaravelChangeDetection\Models\Hash;
use Ameax\LaravelChangeDetection\Models\HashDependent;
use Ameax\LaravelChangeDetection\Models\Publish;
use Ameax\LaravelChangeDetection\Models\Publisher;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;

class HashUpdater
{
    private CompositeHashCalculator $hashCalculator;

    private Connection $connection;

    public function __construct(CompositeHashCalculator $hashCalculator, ?string $connectionName = null)
    {
        $this->hashCalculator = $hashCalculator;
        $this->connection = DB::connection($connectionName ?? config('change-detection.database_connection'));
    }

    /**
     * @param Hashable&\Illuminate\Database\Eloquent\Model $model
     */
    public function updateHash(Hashable $model): Hash
    {
        return $this->connection->transaction(function () use ($model) {
            $attributeHash = $this->hashCalculator->getAttributeCalculator()->calculateAttributeHash($model);

            // Create or update hash with attribute hash first (temporary composite hash)
            $hash = Hash::updateOrCreate(
                [
                    'hashable_type' => $model->getMorphClass(),
                    'hashable_id' => $model->getKey(),
                ],
                [
                    'attribute_hash' => $attributeHash,
                    'composite_hash' => $attributeHash, // Temporary, will be recalculated
                    'deleted_at' => null, // Ensure it's marked as active
                ]
            );

            // Build dependency relationships for this model
            $this->buildDependencyRelationships($model, $hash);

            // Now recalculate composite hash with dependencies in place
            $compositeHash = $this->hashCalculator->calculate($model);
            $hash->update(['composite_hash' => $compositeHash]);

            // Create publish record for this model if publishers exist
            $this->createPublishRecordForModel($model, $hash);

            // Update any models that depend on this one
            $this->updateDependentModels($model);

            return $hash->refresh();
        });
    }

    public function updateHashesBulk(string $modelClass, array $modelIds): array
    {
        return $this->connection->transaction(function () use ($modelClass, $modelIds) {
            $attributeHashes = $this->hashCalculator->getAttributeCalculator()->calculateAttributeHashBulk($modelClass, $modelIds);
            $dependencyHashes = $this->hashCalculator->getDependencyCalculator()->calculateBulk($modelClass, $modelIds);
            $compositeHashes = $this->hashCalculator->calculateBulk($modelClass, $modelIds);

            $morphClass = (new $modelClass)->getMorphClass();
            $hashesTable = config('change-detection.tables.hashes', 'hashes');
            $updatedHashes = [];

            foreach ($modelIds as $modelId) {
                $attributeHash = $attributeHashes[$modelId] ?? null;
                $compositeHash = $compositeHashes[$modelId] ?? null;

                if ($attributeHash && $compositeHash) {
                    // Use INSERT ... ON DUPLICATE KEY UPDATE for performance
                    $now = now()->utc()->toDateTimeString();
                    $sql = "
                        INSERT INTO `{$hashesTable}` (hashable_type, hashable_id, attribute_hash, composite_hash, deleted_at, created_at, updated_at)
                        VALUES (?, ?, ?, ?, NULL, ?, ?)
                        ON DUPLICATE KEY UPDATE
                            attribute_hash = VALUES(attribute_hash),
                            composite_hash = VALUES(composite_hash),
                            deleted_at = NULL,
                            updated_at = VALUES(updated_at)
                    ";

                    $this->connection->statement($sql, [$morphClass, $modelId, $attributeHash, $compositeHash, $now, $now]);
                    $updatedHashes[] = $modelId;
                }
            }

            // Update dependent models for all updated hashes
            $this->updateDependentModelsBulk($modelClass, $updatedHashes);

            return $updatedHashes;
        });
    }

    /**
     * @param Hashable&\Illuminate\Database\Eloquent\Model $model
     */
    public function markAsDeleted(Hashable $model): void
    {
        Hash::where('hashable_type', $model->getMorphClass())
            ->where('hashable_id', $model->getKey())
            ->update(['deleted_at' => now()]);

        // Update any models that depend on this one
        $this->updateDependentModels($model);
    }

    public function markAsDeletedBulk(string $modelClass, array $modelIds): void
    {
        $morphClass = (new $modelClass)->getMorphClass();

        Hash::where('hashable_type', $morphClass)
            ->whereIn('hashable_id', $modelIds)
            ->update(['deleted_at' => now()]);

        // Update dependent models for all deleted hashes
        $this->updateDependentModelsBulk($modelClass, $modelIds);
    }

    /**
     * @param Hashable&\Illuminate\Database\Eloquent\Model $model
     */
    private function updateDependentModels(Hashable $model): void
    {
        // Find all models that depend on this one
        $dependents = HashDependent::whereHas('hash', function ($query) use ($model) {
            $query->where('hashable_type', $model->getMorphClass())
                ->where('hashable_id', $model->getKey());
        })->get();

        foreach ($dependents as $dependent) {
            $dependentModelClass = $dependent->dependent_model_type;
            $dependentModel = $dependentModelClass::find($dependent->dependent_model_id);

            if ($dependentModel instanceof Hashable) {
                /** @var Hashable&\Illuminate\Database\Eloquent\Model $dependentModel */
                $this->updateHash($dependentModel);
            }
        }
    }

    private function updateDependentModelsBulk(string $modelClass, array $modelIds): void
    {
        if (empty($modelIds)) {
            return;
        }

        $morphClass = (new $modelClass)->getMorphClass();
        $hashesTable = config('change-detection.tables.hashes', 'hashes');
        $hashDependentsTable = config('change-detection.tables.hash_dependents', 'hash_dependents');

        // Get all dependent models in one query
        $placeholders = str_repeat('?,', count($modelIds) - 1).'?';
        $sql = "
            SELECT DISTINCT hd.dependent_model_type, hd.dependent_model_id
            FROM `{$hashDependentsTable}` hd
            INNER JOIN `{$hashesTable}` h ON h.id = hd.hash_id
            WHERE h.hashable_type = ?
              AND h.hashable_id IN ({$placeholders})
        ";

        $dependents = $this->connection->select($sql, array_merge([$morphClass], $modelIds));

        // Group by model type for efficient processing
        $dependentsByType = [];
        foreach ($dependents as $dependent) {
            $dependentsByType[$dependent->dependent_model_type][] = $dependent->dependent_model_id;
        }

        // Update each dependent model type in bulk
        foreach ($dependentsByType as $dependentModelClass => $dependentModelIds) {
            if (class_exists($dependentModelClass)) {
                $this->updateHashesBulk($dependentModelClass, $dependentModelIds);
            }
        }
    }

    public function getHashCalculator(): CompositeHashCalculator
    {
        return $this->hashCalculator;
    }

    public function getConnection(): Connection
    {
        return $this->connection;
    }

    /**
     * Build dependency relationships for a model based on its getHashCompositeDependencies().
     * @param Hashable&\Illuminate\Database\Eloquent\Model $model
     */
    private function buildDependencyRelationships(Hashable $model, Hash $dependentHash): void
    {
        $dependencies = $model->getHashCompositeDependencies();
        if (empty($dependencies)) {
            return;
        }

        foreach ($dependencies as $relationName) {
            $this->buildDependencyForRelation($model, $dependentHash, $relationName);
        }
    }

    /**
     * Build dependency relationships for a specific relation.
     * @param Hashable&\Illuminate\Database\Eloquent\Model $dependentModel
     */
    private function buildDependencyForRelation(Hashable $dependentModel, Hash $dependentHash, string $relationName): void
    {
        if (! method_exists($dependentModel, $relationName)) {
            return;
        }

        try {
            $relation = $dependentModel->{$relationName}();

            // Apply scope if the related model has one
            $relatedModel = $relation->getRelated();
            if ($relatedModel instanceof Hashable) {
                $scope = $relatedModel->getHashableScope();
                if ($scope) {
                    $scope($relation);
                }
            }

            $relatedModels = $relation->get();

            foreach ($relatedModels as $relatedModel) {
                if ($relatedModel instanceof Hashable) {
                    // Ensure the related model has a hash
                    $relatedHash = $relatedModel->getCurrentHash();
                    if (! $relatedHash) {
                        // Create a basic hash for the related model if it doesn't exist
                        // This ensures new records get hashes and can be dependencies
                        /** @var Hashable&\Illuminate\Database\Eloquent\Model $relatedModel */
                        $attributeHash = $this->hashCalculator->getAttributeCalculator()->calculateAttributeHash($relatedModel);

                        $relatedHash = Hash::create([
                            'hashable_type' => $relatedModel->getMorphClass(),
                            'hashable_id' => $relatedModel->getKey(),
                            'attribute_hash' => $attributeHash,
                            'composite_hash' => $attributeHash, // Initially same as attribute hash
                        ]);
                    }

                    // Create dependency relationship: the related model's hash affects the dependent model's composite hash
                    HashDependent::updateOrCreate([
                        'hash_id' => $relatedHash->id,
                        'dependent_model_type' => $dependentModel->getMorphClass(),
                        'dependent_model_id' => $dependentModel->getKey(),
                        'relation_name' => $relationName,
                    ]);
                }
            }
        } catch (\Exception $e) {
            // Log error but don't fail the entire hash update process
            \Illuminate\Support\Facades\Log::warning(
                "Failed to build dependency for relation {$relationName} on ".get_class($dependentModel),
                ['error' => $e->getMessage()]
            );
        }
    }

    /**
     * Create publish record for the model itself if publishers exist and no record exists yet.
     * @param Hashable&\Illuminate\Database\Eloquent\Model $model
     */
    private function createPublishRecordForModel(Hashable $model, Hash $hash): void
    {
        // Get all active publishers for this model type
        $publishers = Publisher::where('model_type', $model->getMorphClass())
            ->where('status', 'active')
            ->get();

        foreach ($publishers as $publisher) {
            // Check if a publish record already exists for this hash and publisher
            $exists = Publish::where('hash_id', $hash->id)
                ->where('publisher_id', $publisher->id)
                ->exists();

            if (! $exists) {
                // Create publish record
                Publish::create([
                    'hash_id' => $hash->id,
                    'publisher_id' => $publisher->id,
                    'published_hash' => null, // Initially null, will be set when publishing is successful
                    'status' => 'pending',
                    'attempts' => 0,
                    'metadata' => [
                        'model_type' => $model->getMorphClass(),
                        'model_id' => $model->getKey(),
                    ],
                ]);
            }
        }
    }
}
