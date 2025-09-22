<?php

declare(strict_types=1);

namespace Ameax\LaravelChangeDetection\Services;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;

class BulkHashProcessor
{
    private CompositeHashCalculator $hashCalculator;

    private Connection $connection;

    private int $batchSize;

    public function __construct(
        CompositeHashCalculator $hashCalculator,
        ?string $connectionName = null,
        int $batchSize = 1000
    ) {
        $this->hashCalculator = $hashCalculator;
        $this->connection = DB::connection($connectionName ?? config('change-detection.database_connection'));
        $this->batchSize = $batchSize;
    }

    /**
     * @param class-string<\Illuminate\Database\Eloquent\Model&\Ameax\LaravelChangeDetection\Contracts\Hashable> $modelClass
     */
    public function processChangedModels(string $modelClass, ?int $limit = null): int
    {
        $detector = app(ChangeDetector::class);
        $changedIds = $detector->detectChangedModelIds($modelClass, $limit);

        if (empty($changedIds)) {
            return 0;
        }

        return $this->updateHashesForIds($modelClass, $changedIds);
    }

    /**
     * @param class-string<\Illuminate\Database\Eloquent\Model&\Ameax\LaravelChangeDetection\Contracts\Hashable> $modelClass
     * @param array<int> $modelIds
     */
    public function updateHashesForIds(string $modelClass, array $modelIds): int
    {
        if (empty($modelIds)) {
            return 0;
        }

        $updated = 0;
        $chunks = array_chunk($modelIds, $this->batchSize);

        foreach ($chunks as $chunk) {
            $updated += $this->updateHashesChunk($modelClass, $chunk);
        }

        return $updated;
    }

    /**
     * @param class-string<\Illuminate\Database\Eloquent\Model&\Ameax\LaravelChangeDetection\Contracts\Hashable> $modelClass
     * @param array<int> $modelIds
     */
    private function updateHashesChunk(string $modelClass, array $modelIds): int
    {
        $model = new $modelClass;
        $table = $model->getTable();
        $primaryKey = $model->getKeyName();
        $morphClass = $model->getMorphClass();
        $attributes = $model->getHashableAttributes();
        $hashesTable = config('change-detection.tables.hashes', 'hashes');
        $hashDependentsTable = config('change-detection.tables.hash_dependents', 'hash_dependents');

        // Get database names for cross-database queries
        $modelConnection = $model->getConnection();
        $modelDatabase = $modelConnection->getDatabaseName();
        $qualifiedTable = $modelDatabase ? "`{$modelDatabase}`.`{$table}`" : "`{$table}`";

        $hashDatabase = $this->connection->getDatabaseName();
        $qualifiedHashesTable = $hashDatabase ? "`{$hashDatabase}`.`{$hashesTable}`" : "`{$hashesTable}`";
        $qualifiedHashDependentsTable = $hashDatabase ? "`{$hashDatabase}`.`{$hashDependentsTable}`" : "`{$hashDependentsTable}`";

        sort($attributes);

        $concatParts = [];
        foreach ($attributes as $attribute) {
            $concatParts[] = "IFNULL(CAST(m.`{$attribute}` AS CHAR), '')";
        }
        $attributeHashExpr = 'MD5(CONCAT('.implode(", '|', ", $concatParts).'))';

        $dependencyHashExpr = "
            (SELECT MD5(GROUP_CONCAT(
                IFNULL(dh.composite_hash, dh.attribute_hash)
                ORDER BY dhd.id, dh.hashable_type, dh.hashable_id
                SEPARATOR '|'
            ))
            FROM {$qualifiedHashDependentsTable} dhd
            INNER JOIN {$qualifiedHashesTable} dh
                ON dh.id = dhd.hash_id
                AND dh.deleted_at IS NULL
            WHERE dhd.dependent_model_type = '{$morphClass}'
              AND dhd.dependent_model_id = m.`{$primaryKey}`)
        ";

        $compositeHashExpr = "
            CASE
                WHEN {$dependencyHashExpr} IS NULL THEN {$attributeHashExpr}
                ELSE MD5(CONCAT({$attributeHashExpr}, '|', {$dependencyHashExpr}))
            END
        ";

        // Add scope filtering
        $scopeClause = $this->buildScopeSubquery($modelClass, 'm', $primaryKey);
        $scopeBindings = $this->getScopeBindings($modelClass);

        $idsPlaceholder = str_repeat('?,', count($modelIds) - 1).'?';

        $now = now()->utc()->toDateTimeString();
        $sql = "
            INSERT INTO {$qualifiedHashesTable} (hashable_type, hashable_id, attribute_hash, composite_hash, created_at, updated_at)
            SELECT
                ? as hashable_type,
                m.`{$primaryKey}` as hashable_id,
                {$attributeHashExpr} as attribute_hash,
                {$compositeHashExpr} as composite_hash,
                ? as created_at,
                ? as updated_at
            FROM {$qualifiedTable} m
            WHERE m.`{$primaryKey}` IN ({$idsPlaceholder})
            {$scopeClause}
            ON DUPLICATE KEY UPDATE
                attribute_hash = VALUES(attribute_hash),
                composite_hash = VALUES(composite_hash),
                updated_at = VALUES(updated_at),
                deleted_at = NULL
        ";

        $bindings = array_merge([$morphClass, $now, $now], $modelIds, $scopeBindings);
        $this->connection->statement($sql, $bindings);

        // Build dependency relationships for the updated models
        $this->buildDependencyRelationshipsForIds($modelClass, $modelIds);

        // Recalculate composite hashes now that dependencies are in place
        $this->recalculateCompositeHashesForIds($modelClass, $modelIds);

        // Create publish records for models that have publishers
        $this->createPublishRecordsForIds($modelClass, $modelIds);

        return count($modelIds); // Return count of processed IDs
    }

    /**
     * @param class-string<\Illuminate\Database\Eloquent\Model&\Ameax\LaravelChangeDetection\Contracts\Hashable> $modelClass
     */
    public function softDeleteHashesForDeletedModels(string $modelClass): int
    {
        $model = new $modelClass;
        $table = $model->getTable();
        $primaryKey = $model->getKeyName();
        $morphClass = $model->getMorphClass();
        $hashesTable = config('change-detection.tables.hashes', 'hashes');

        // Get database names for cross-database queries
        $modelConnection = $model->getConnection();
        $modelDatabase = $modelConnection->getDatabaseName();
        $qualifiedTable = $modelDatabase ? "`{$modelDatabase}`.`{$table}`" : "`{$table}`";

        $hashDatabase = $this->connection->getDatabaseName();
        $qualifiedHashesTable = $hashDatabase ? "`{$hashDatabase}`.`{$hashesTable}`" : "`{$hashesTable}`";

        // Check if the model has a deleted_at column
        try {
            $schema = $model->getConnection()->getSchemaBuilder();
            if (! $schema->hasColumn($model->getTable(), 'deleted_at')) {
                return 0;
            }
        } catch (\Exception $e) {
            return 0;
        }

        // Add scope filtering
        $scopeClause = $this->buildScopeSubquery($modelClass, 'm', $primaryKey);
        $scopeBindings = $this->getScopeBindings($modelClass);

        $sql = "
            UPDATE {$qualifiedHashesTable} h
            INNER JOIN {$qualifiedTable} m ON m.`{$primaryKey}` = h.hashable_id
            SET h.deleted_at = m.deleted_at
            WHERE h.hashable_type = ?
              AND h.deleted_at IS NULL
              AND m.deleted_at IS NOT NULL
            {$scopeClause}
        ";

        $bindings = array_merge([$morphClass], $scopeBindings);

        return $this->connection->update($sql, $bindings);
    }

    /**
     * @param class-string<\Illuminate\Database\Eloquent\Model&\Ameax\LaravelChangeDetection\Contracts\Hashable> $modelClass
     */
    public function cleanupOrphanedHashes(string $modelClass): int
    {
        $model = new $modelClass;
        $table = $model->getTable();
        $primaryKey = $model->getKeyName();
        $morphClass = $model->getMorphClass();
        $hashesTable = config('change-detection.tables.hashes', 'hashes');

        // Get database names for cross-database queries
        $modelConnection = $model->getConnection();
        $modelDatabase = $modelConnection->getDatabaseName();
        $qualifiedTable = $modelDatabase ? "`{$modelDatabase}`.`{$table}`" : "`{$table}`";

        $hashDatabase = $this->connection->getDatabaseName();
        $qualifiedHashesTable = $hashDatabase ? "`{$hashDatabase}`.`{$hashesTable}`" : "`{$hashesTable}`";

        // Add scope filtering - for orphaned cleanup, we need to consider scoped records only
        $scopeClause = $this->buildScopeSubquery($modelClass, 'm', $primaryKey);
        $scopeBindings = $this->getScopeBindings($modelClass);

        $now = now()->utc()->toDateTimeString();
        $sql = "
            UPDATE {$qualifiedHashesTable} h
            LEFT JOIN {$qualifiedTable} m ON m.`{$primaryKey}` = h.hashable_id
            SET h.deleted_at = ?
            WHERE h.hashable_type = ?
              AND h.deleted_at IS NULL
              AND (m.`{$primaryKey}` IS NULL".(! empty($scopeClause) ? " OR NOT EXISTS (SELECT 1 FROM {$qualifiedTable} scoped WHERE scoped.`{$primaryKey}` = h.hashable_id {$scopeClause})" : '').')
        ';

        $bindings = array_merge([$now, $morphClass], $scopeBindings);
        $updated = $this->connection->update($sql, $bindings);

        // If hashes were marked as deleted, update any models that depend on them
        if ($updated > 0) {
            $this->updateDependentModelsAfterCleanup($modelClass);
        }

        return $updated;
    }

    public function setBatchSize(int $batchSize): void
    {
        $this->batchSize = max(1, $batchSize);
    }

    public function getBatchSize(): int
    {
        return $this->batchSize;
    }

    public function getConnection(): Connection
    {
        return $this->connection;
    }

    /**
     * Build a subquery for scoped model filtering.
     * Returns empty string if no scope is defined.
     * @param class-string<\Illuminate\Database\Eloquent\Model&\Ameax\LaravelChangeDetection\Contracts\Hashable> $modelClass
     */
    private function buildScopeSubquery(string $modelClass, string $tableAlias, string $primaryKey): string
    {
        $model = new $modelClass;
        $scope = $model->getHashableScope();

        if (! $scope) {
            return ''; // No scope defined, no filtering needed
        }

        // Create a query with the scope applied to get the subquery SQL
        $query = $modelClass::query();
        $scope($query);

        // Get the SQL and bindings from the scoped query
        $subquerySql = $query->select($model->getKeyName())->toSql();

        // Build qualified table name for cross-database compatibility
        $modelConnection = $model->getConnection();
        $modelDatabase = $modelConnection->getDatabaseName();
        $table = $model->getTable();

        if ($modelDatabase) {
            // Replace the table name in the subquery with the qualified table name
            $qualifiedTable = "`{$modelDatabase}`.`{$table}`";
            $subquerySql = str_replace("`{$table}`", $qualifiedTable, $subquerySql);
        }

        return " AND {$tableAlias}.`{$primaryKey}` IN ({$subquerySql})";
    }

    /**
     * Get bindings from a scoped query for use in raw SQL.
     * @param class-string<\Illuminate\Database\Eloquent\Model&\Ameax\LaravelChangeDetection\Contracts\Hashable> $modelClass
     * @return array<mixed>
     */
    private function getScopeBindings(string $modelClass): array
    {
        $model = new $modelClass;
        $scope = $model->getHashableScope();

        if (! $scope) {
            return [];
        }

        $query = $modelClass::query();
        $scope($query);

        return $query->getBindings();
    }

    /**
     * Build dependency relationships for multiple model IDs.
     * @param class-string<\Illuminate\Database\Eloquent\Model&\Ameax\LaravelChangeDetection\Contracts\Hashable> $modelClass
     * @param array<int> $modelIds
     */
    private function buildDependencyRelationshipsForIds(string $modelClass, array $modelIds): void
    {
        if (empty($modelIds)) {
            return;
        }

        $model = new $modelClass;
        $dependencies = $model->getHashCompositeDependencies();
        if (empty($dependencies)) {
            return;
        }

        // Process in smaller chunks to avoid memory issues
        $chunks = array_chunk($modelIds, 100);

        foreach ($chunks as $chunk) {
            /** @var \Illuminate\Database\Eloquent\Builder<\Illuminate\Database\Eloquent\Model&\Ameax\LaravelChangeDetection\Contracts\Hashable> */
            $models = $modelClass::whereIn($model->getKeyName(), $chunk);

            // Apply scope if defined
            $scope = $model->getHashableScope();
            if ($scope) {
                $scope($models);
            }

            /** @var \Illuminate\Database\Eloquent\Collection<int, \Illuminate\Database\Eloquent\Model&\Ameax\LaravelChangeDetection\Contracts\Hashable> */
            $models = $models->get();

            foreach ($models as $model) {
                /** @var \Illuminate\Database\Eloquent\Model&\Ameax\LaravelChangeDetection\Contracts\Hashable $model */
                $this->buildDependencyRelationshipsForModel($model);
            }
        }
    }

    /**
     * Build dependency relationships for a single model.
     * @param \Ameax\LaravelChangeDetection\Contracts\Hashable&\Illuminate\Database\Eloquent\Model $model
     */
    /**
     * @param \Ameax\LaravelChangeDetection\Contracts\Hashable&\Illuminate\Database\Eloquent\Model $model
     */
    private function buildDependencyRelationshipsForModel(\Ameax\LaravelChangeDetection\Contracts\Hashable $model): void
    {
        $dependencies = $model->getHashCompositeDependencies();
        if (empty($dependencies)) {
            return;
        }

        $dependentHash = $model->getCurrentHash();
        if (! $dependentHash) {
            return; // Skip if no hash exists
        }

        foreach ($dependencies as $relationName) {
            if (! method_exists($model, $relationName)) {
                continue;
            }

            try {
                $relation = $model->{$relationName}();

                // Apply scope if the related model has one
                $relatedModel = $relation->getRelated();
                if ($relatedModel instanceof \Ameax\LaravelChangeDetection\Contracts\Hashable) {
                    $scope = $relatedModel->getHashableScope();
                    if ($scope) {
                        $scope($relation);
                    }
                }

                $relatedModels = $relation->get();

                foreach ($relatedModels as $relatedModel) {
                    if ($relatedModel instanceof \Ameax\LaravelChangeDetection\Contracts\Hashable) {
                        $relatedHash = $relatedModel->getCurrentHash();
                        if (! $relatedHash) {
                            // Create a basic hash for the related model if it doesn't exist
                            // This ensures new records get hashes and can be dependencies
                            /** @var \Ameax\LaravelChangeDetection\Contracts\Hashable&\Illuminate\Database\Eloquent\Model $relatedModel */
                            $attributeHash = $this->hashCalculator->getAttributeCalculator()->calculateAttributeHash($relatedModel);

                            $relatedHash = \Ameax\LaravelChangeDetection\Models\Hash::create([
                                'hashable_type' => $relatedModel->getMorphClass(),
                                'hashable_id' => $relatedModel->getKey(),
                                'attribute_hash' => $attributeHash,
                                'composite_hash' => $attributeHash, // Initially same as attribute hash
                            ]);
                        }

                        // Create dependency relationship if it doesn't exist
                        \Ameax\LaravelChangeDetection\Models\HashDependent::updateOrCreate([
                            'hash_id' => $relatedHash->id,
                            'dependent_model_type' => $model->getMorphClass(),
                            'dependent_model_id' => $model->getKey(),
                            'relation_name' => $relationName,
                        ]);
                    }
                }
            } catch (\Exception $e) {
                // Log error but continue processing
                \Illuminate\Support\Facades\Log::warning(
                    "Failed to build dependency for relation {$relationName} on ".get_class($model),
                    ['error' => $e->getMessage()]
                );
            }
        }
    }

    /**
     * Recalculate composite hashes for model IDs after dependencies are created.
     * @param class-string<\Illuminate\Database\Eloquent\Model&\Ameax\LaravelChangeDetection\Contracts\Hashable> $modelClass
     * @param array<int> $modelIds
     */
    private function recalculateCompositeHashesForIds(string $modelClass, array $modelIds): void
    {
        if (empty($modelIds)) {
            return;
        }

        $model = new $modelClass;
        $dependencies = $model->getHashCompositeDependencies();
        if (empty($dependencies)) {
            return; // No dependencies, composite hash = attribute hash (already correct)
        }

        // Process in smaller chunks
        $chunks = array_chunk($modelIds, 100);

        foreach ($chunks as $chunk) {
            /** @var \Illuminate\Database\Eloquent\Builder<\Illuminate\Database\Eloquent\Model&\Ameax\LaravelChangeDetection\Contracts\Hashable> */
            $models = $modelClass::whereIn($model->getKeyName(), $chunk);

            // Apply scope if defined
            $scope = $model->getHashableScope();
            if ($scope) {
                $scope($models);
            }

            $models = $models->get();

            foreach ($models as $model) {
                /** @var \Illuminate\Database\Eloquent\Model&\Ameax\LaravelChangeDetection\Contracts\Hashable $model */
                // Recalculate composite hash with dependencies now in place
                $compositeHash = $this->hashCalculator->calculate($model);

                // Update the hash record
                \Ameax\LaravelChangeDetection\Models\Hash::where('hashable_type', $model->getMorphClass())
                    ->where('hashable_id', $model->getKey())
                    ->update(['composite_hash' => $compositeHash]);
            }
        }
    }

    /**
     * Create publish records for model IDs that have active publishers.
     * @param class-string<\Illuminate\Database\Eloquent\Model&\Ameax\LaravelChangeDetection\Contracts\Hashable> $modelClass
     * @param array<int> $modelIds
     */
    private function createPublishRecordsForIds(string $modelClass, array $modelIds): void
    {
        if (empty($modelIds)) {
            return;
        }

        $model = new $modelClass;
        $morphClass = $model->getMorphClass();

        // Get all active publishers for this model type
        $publishers = \Ameax\LaravelChangeDetection\Models\Publisher::where('model_type', $morphClass)
            ->where('status', 'active')
            ->get();

        if ($publishers->isEmpty()) {
            return; // No publishers configured for this model type
        }

        // Get all hashes for these model IDs
        $hashes = \Ameax\LaravelChangeDetection\Models\Hash::where('hashable_type', $morphClass)
            ->whereIn('hashable_id', $modelIds)
            ->whereNull('deleted_at')
            ->get();

        foreach ($hashes as $hash) {
            foreach ($publishers as $publisher) {
                // Check if a publish record already exists for this hash and publisher
                $exists = \Ameax\LaravelChangeDetection\Models\Publish::where('hash_id', $hash->id)
                    ->where('publisher_id', $publisher->id)
                    ->exists();

                if (! $exists) {
                    // Create publish record
                    \Ameax\LaravelChangeDetection\Models\Publish::create([
                        'hash_id' => $hash->id,
                        'publisher_id' => $publisher->id,
                        'published_hash' => null, // Initially null, will be set when publishing is successful
                        'status' => 'pending',
                        'attempts' => 0,
                        'metadata' => [
                            'model_type' => $morphClass,
                            'model_id' => $hash->hashable_id,
                        ],
                    ]);
                }
            }
        }
    }

    /**
     * Update models that depend on the cleaned up (deleted) hashes of the given model class.
     * @param class-string<\Illuminate\Database\Eloquent\Model&\Ameax\LaravelChangeDetection\Contracts\Hashable> $modelClass
     */
    private function updateDependentModelsAfterCleanup(string $modelClass): void
    {
        $model = new $modelClass;
        $morphClass = $model->getMorphClass();
        $hashesTable = config('change-detection.tables.hashes', 'hashes');
        $hashDependentsTable = config('change-detection.tables.hash_dependents', 'hash_dependents');

        // Find all dependent models that reference deleted hashes of this model type
        $sql = "
            SELECT DISTINCT hd.dependent_model_type, hd.dependent_model_id
            FROM `{$hashDependentsTable}` hd
            INNER JOIN `{$hashesTable}` h ON h.id = hd.hash_id
            WHERE h.hashable_type = ?
              AND h.deleted_at IS NOT NULL
        ";

        $dependents = $this->connection->select($sql, [$morphClass]);

        // Group by model type for efficient processing
        $dependentsByType = [];
        foreach ($dependents as $dependent) {
            $dependentsByType[$dependent->dependent_model_type][] = $dependent->dependent_model_id;
        }

        // Update each dependent model type in bulk
        foreach ($dependentsByType as $dependentMorphClass => $dependentModelIds) {
            // For now, we'll trigger a dependent models update using the HashUpdater approach
            // which already handles individual model updates properly
            $this->updateDependentModelsByMorphClass($dependentMorphClass, $dependentModelIds);
        }
    }

    /**
     * Update dependent models by their morph class by directly updating their hashes.
     * @param array<int> $modelIds
     */
    private function updateDependentModelsByMorphClass(string $morphClass, array $modelIds): void
    {
        if (empty($modelIds)) {
            return;
        }

        // Use the existing bulk update approach but directly with the morph class
        // We can recreate the hash records directly without needing the model class
        $hashesTable = config('change-detection.tables.hashes', 'hashes');

        // Mark these dependent model hashes for recalculation by updating their updated_at
        // This will make them appear as "changed" in the next detection run
        $placeholders = str_repeat('?,', count($modelIds) - 1).'?';
        $now = now()->utc()->toDateTimeString();

        $sql = "
            UPDATE `{$hashesTable}`
            SET updated_at = ?
            WHERE hashable_type = ?
              AND hashable_id IN ({$placeholders})
              AND deleted_at IS NULL
        ";

        $bindings = array_merge([$now, $morphClass], $modelIds);
        $this->connection->update($sql, $bindings);
    }
}
