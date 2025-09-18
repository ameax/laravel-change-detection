<?php

declare(strict_types=1);

namespace Ameax\LaravelChangeDetection\Services;

use Ameax\LaravelChangeDetection\Contracts\Hashable;
use Ameax\LaravelChangeDetection\Models\Hash;
use Ameax\LaravelChangeDetection\Models\HashDependent;
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

    public function updateHash(Hashable $model): Hash
    {
        return $this->connection->transaction(function () use ($model) {
            $attributeHash = $this->hashCalculator->getAttributeCalculator()->calculateAttributeHash($model);
            $dependencyHash = $this->hashCalculator->getDependencyCalculator()->calculate($model);
            $compositeHash = $this->hashCalculator->calculate($model);

            $hash = Hash::updateOrCreate(
                [
                    'hashable_type' => $model->getMorphClass(),
                    'hashable_id' => $model->getKey(),
                ],
                [
                    'attribute_hash' => $attributeHash,
                    'composite_hash' => $compositeHash,
                    'deleted_at' => null, // Ensure it's marked as active
                ]
            );

            // Update any models that depend on this one
            $this->updateDependentModels($model);

            return $hash;
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
                    $sql = "
                        INSERT INTO `{$hashesTable}` (hashable_type, hashable_id, attribute_hash, composite_hash, deleted_at, created_at, updated_at)
                        VALUES (?, ?, ?, ?, NULL, NOW(), NOW())
                        ON DUPLICATE KEY UPDATE
                            attribute_hash = VALUES(attribute_hash),
                            composite_hash = VALUES(composite_hash),
                            deleted_at = NULL,
                            updated_at = NOW()
                    ";

                    $this->connection->statement($sql, [$morphClass, $modelId, $attributeHash, $compositeHash]);
                    $updatedHashes[] = $modelId;
                }
            }

            // Update dependent models for all updated hashes
            $this->updateDependentModelsBulk($modelClass, $updatedHashes);

            return $updatedHashes;
        });
    }

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
}
