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

    public function processChangedModels(string $modelClass, ?int $limit = null): int
    {
        $detector = app(ChangeDetector::class);
        $changedIds = $detector->detectChangedModelIds($modelClass, $limit);

        if (empty($changedIds)) {
            return 0;
        }

        return $this->updateHashesForIds($modelClass, $changedIds);
    }

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

        $idsPlaceholder = str_repeat('?,', count($modelIds) - 1).'?';

        $sql = "
            INSERT INTO {$qualifiedHashesTable} (hashable_type, hashable_id, attribute_hash, composite_hash, created_at, updated_at)
            SELECT
                ? as hashable_type,
                m.`{$primaryKey}` as hashable_id,
                {$attributeHashExpr} as attribute_hash,
                {$compositeHashExpr} as composite_hash,
                NOW() as created_at,
                NOW() as updated_at
            FROM {$qualifiedTable} m
            WHERE m.`{$primaryKey}` IN ({$idsPlaceholder})
            ON DUPLICATE KEY UPDATE
                attribute_hash = VALUES(attribute_hash),
                composite_hash = VALUES(composite_hash),
                updated_at = VALUES(updated_at),
                deleted_at = NULL
        ";

        $bindings = array_merge([$morphClass], $modelIds);
        $this->connection->statement($sql, $bindings);

        return count($modelIds); // Return count of processed IDs
    }

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

        if (! in_array('deleted_at', $model->getFillable()) && ! $model->hasColumn('deleted_at')) {
            return 0;
        }

        $sql = "
            UPDATE {$qualifiedHashesTable} h
            INNER JOIN {$qualifiedTable} m ON m.`{$primaryKey}` = h.hashable_id
            SET h.deleted_at = m.deleted_at
            WHERE h.hashable_type = ?
              AND h.deleted_at IS NULL
              AND m.deleted_at IS NOT NULL
        ";

        return $this->connection->update($sql, [$morphClass]);
    }

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

        $sql = "
            UPDATE {$qualifiedHashesTable} h
            LEFT JOIN {$qualifiedTable} m ON m.`{$primaryKey}` = h.hashable_id
            SET h.deleted_at = NOW()
            WHERE h.hashable_type = ?
              AND h.deleted_at IS NULL
              AND m.`{$primaryKey}` IS NULL
        ";

        return $this->connection->update($sql, [$morphClass]);
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
}
