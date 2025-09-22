<?php

declare(strict_types=1);

namespace Ameax\LaravelChangeDetection\Services;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;

class OrphanedHashDetector
{
    private CrossDatabaseQueryBuilder $crossDbBuilder;

    private Connection $connection;

    public function __construct(
        ?CrossDatabaseQueryBuilder $crossDbBuilder = null,
        ?string $connectionName = null
    ) {
        $this->crossDbBuilder = $crossDbBuilder ?? new CrossDatabaseQueryBuilder($connectionName);
        $this->connection = DB::connection($connectionName ?? config('change-detection.database_connection'));
    }

    /**
     * @param  class-string  $modelClass
     * @return array<int, array{hash_id: int, model_id: int}>
     */
    public function detectOrphanedHashes(string $modelClass, ?int $limit = null): array
    {
        /** @var \Illuminate\Database\Eloquent\Model&\Ameax\LaravelChangeDetection\Contracts\Hashable $model */
        $model = new $modelClass;
        $table = $model->getTable();
        $primaryKey = $model->getKeyName();
        $morphClass = $model->getMorphClass();
        $modelConnectionName = $model->getConnectionName();
        $scope = $model->getHashableScope();

        $hashesTable = config('change-detection.tables.hashes', 'hashes');

        $modelTableName = $this->crossDbBuilder->buildCrossDatabaseTableName($table, $modelConnectionName);
        $hashesTableName = $this->crossDbBuilder->buildHashTableName($hashesTable);

        $limitClause = $limit ? "LIMIT {$limit}" : '';

        // Build the WHERE clause based on whether a scope is defined
        if ($scope) {
            // Get scope SQL and bindings
            $scopeClause = $this->buildScopeSubquery($modelClass, 'm', $primaryKey, $modelTableName);
            $scopeBindings = $this->getScopeBindings($modelClass);

            // Records are orphaned if they don't exist OR are outside the scope
            $sql = "
                SELECT h.id as hash_id, h.hashable_id as model_id
                FROM {$hashesTableName} h
                LEFT JOIN {$modelTableName} m ON m.`{$primaryKey}` = h.hashable_id
                WHERE h.hashable_type = ?
                  AND h.deleted_at IS NULL
                  AND (m.`{$primaryKey}` IS NULL OR NOT EXISTS (
                      SELECT 1 FROM {$modelTableName} scoped
                      WHERE scoped.`{$primaryKey}` = h.hashable_id
                      {$scopeClause}
                  ))
                {$limitClause}
            ";

            $bindings = array_merge([$morphClass], $scopeBindings);
        } else {
            // No scope - only check if record exists
            $sql = "
                SELECT h.id as hash_id, h.hashable_id as model_id
                FROM {$hashesTableName} h
                LEFT JOIN {$modelTableName} m ON m.`{$primaryKey}` = h.hashable_id
                WHERE h.hashable_type = ?
                  AND h.deleted_at IS NULL
                  AND m.`{$primaryKey}` IS NULL
                {$limitClause}
            ";

            $bindings = [$morphClass];
        }

        $results = $this->crossDbBuilder->executeCrossDatabaseQuery($sql, $bindings);

        return array_map(function ($result) {
            return [
                'hash_id' => $result->hash_id,
                'model_id' => $result->model_id,
            ];
        }, $results);
    }

    /**
     * @param  class-string  $modelClass
     * @return array<int, array{hash_id: int, model_id: int, model_deleted_at: string}>
     */
    public function detectSoftDeletedModelHashes(string $modelClass, ?int $limit = null): array
    {
        /** @var \Illuminate\Database\Eloquent\Model&\Ameax\LaravelChangeDetection\Contracts\Hashable $model */
        $model = new $modelClass;
        $table = $model->getTable();
        $primaryKey = $model->getKeyName();
        $morphClass = $model->getMorphClass();
        $modelConnectionName = $model->getConnectionName();

        // Check if model supports soft deletes
        if (! in_array('Illuminate\Database\Eloquent\SoftDeletes', class_uses_recursive($modelClass))) {
            return [];
        }

        $hashesTable = config('change-detection.tables.hashes', 'hashes');

        $modelTableName = $this->crossDbBuilder->buildCrossDatabaseTableName($table, $modelConnectionName);
        $hashesTableName = $this->crossDbBuilder->buildHashTableName($hashesTable);

        $limitClause = $limit ? "LIMIT {$limit}" : '';

        $sql = "
            SELECT h.id as hash_id, h.hashable_id as model_id, m.deleted_at as model_deleted_at
            FROM {$hashesTableName} h
            INNER JOIN {$modelTableName} m ON m.`{$primaryKey}` = h.hashable_id
            WHERE h.hashable_type = ?
              AND h.deleted_at IS NULL
              AND m.deleted_at IS NOT NULL
            {$limitClause}
        ";

        $results = $this->crossDbBuilder->executeCrossDatabaseQuery($sql, [$morphClass]);

        return array_map(function ($result) {
            return [
                'hash_id' => $result->hash_id,
                'model_id' => $result->model_id,
                'model_deleted_at' => $result->model_deleted_at,
            ];
        }, $results);
    }

    /**
     * @param  array<int>  $hashIds
     */
    public function markHashesAsDeleted(array $hashIds, ?string $deletedAt = null): int
    {
        if (empty($hashIds)) {
            return 0;
        }

        $deletedAt = $deletedAt ?? now()->toDateTimeString();
        $hashesTable = config('change-detection.tables.hashes', 'hashes');

        $idsPlaceholder = str_repeat('?,', count($hashIds) - 1).'?';
        $bindings = array_merge($hashIds, [$deletedAt]);

        $sql = "
            UPDATE `{$hashesTable}`
            SET deleted_at = ?
            WHERE id IN ({$idsPlaceholder})
              AND deleted_at IS NULL
        ";

        return $this->connection->update($sql, [$deletedAt, ...$hashIds]);
    }

    public function countOrphanedHashes(string $modelClass): int
    {
        /** @var \Illuminate\Database\Eloquent\Model&\Ameax\LaravelChangeDetection\Contracts\Hashable $model */
        $model = new $modelClass;
        $table = $model->getTable();
        $primaryKey = $model->getKeyName();
        $morphClass = $model->getMorphClass();
        $modelConnectionName = $model->getConnectionName();
        $scope = $model->getHashableScope();

        $hashesTable = config('change-detection.tables.hashes', 'hashes');

        $modelTableName = $this->crossDbBuilder->buildCrossDatabaseTableName($table, $modelConnectionName);
        $hashesTableName = $this->crossDbBuilder->buildHashTableName($hashesTable);

        // Build the WHERE clause based on whether a scope is defined
        if ($scope) {
            // Get scope SQL and bindings
            $scopeClause = $this->buildScopeSubquery($modelClass, 'm', $primaryKey, $modelTableName);
            $scopeBindings = $this->getScopeBindings($modelClass);

            $sql = "
                SELECT COUNT(*) as orphaned_count
                FROM {$hashesTableName} h
                LEFT JOIN {$modelTableName} m ON m.`{$primaryKey}` = h.hashable_id
                WHERE h.hashable_type = ?
                  AND h.deleted_at IS NULL
                  AND (m.`{$primaryKey}` IS NULL OR NOT EXISTS (
                      SELECT 1 FROM {$modelTableName} scoped
                      WHERE scoped.`{$primaryKey}` = h.hashable_id
                      {$scopeClause}
                  ))
            ";

            $bindings = array_merge([$morphClass], $scopeBindings);
        } else {
            $sql = "
                SELECT COUNT(*) as orphaned_count
                FROM {$hashesTableName} h
                LEFT JOIN {$modelTableName} m ON m.`{$primaryKey}` = h.hashable_id
                WHERE h.hashable_type = ?
                  AND h.deleted_at IS NULL
                  AND m.`{$primaryKey}` IS NULL
            ";

            $bindings = [$morphClass];
        }

        $result = $this->crossDbBuilder->executeCrossDatabaseQuery($sql, $bindings);

        return (int) $result[0]->orphaned_count;
    }

    /**
     * @param  class-string  $modelClass
     */
    public function cleanupOrphanedHashes(string $modelClass, ?int $limit = null): int
    {
        $orphanedHashes = $this->detectOrphanedHashes($modelClass, $limit);

        if (empty($orphanedHashes)) {
            return 0;
        }

        $hashIds = array_column($orphanedHashes, 'hash_id');

        return $this->markHashesAsDeleted($hashIds);
    }

    /**
     * @param  class-string  $modelClass
     */
    public function cleanupSoftDeletedModelHashes(string $modelClass, ?int $limit = null): int
    {
        $softDeletedHashes = $this->detectSoftDeletedModelHashes($modelClass, $limit);

        if (empty($softDeletedHashes)) {
            return 0;
        }

        $updates = 0;
        foreach ($softDeletedHashes as $hashData) {
            $updated = $this->markHashesAsDeleted(
                [$hashData['hash_id']],
                $hashData['model_deleted_at']
            );
            $updates += $updated;
        }

        return $updates;
    }

    public function getCrossDbBuilder(): CrossDatabaseQueryBuilder
    {
        return $this->crossDbBuilder;
    }

    public function getConnection(): Connection
    {
        return $this->connection;
    }

    /**
     * Immediately purge orphaned hashes (delete from database).
     * This is different from cleanupOrphanedHashes which only marks them as deleted.
     *
     * @param  class-string  $modelClass
     * @return int Number of purged records
     */
    public function purgeOrphanedHashes(string $modelClass, ?int $limit = null): int
    {
        $orphanedHashes = $this->detectOrphanedHashes($modelClass, $limit);

        if (empty($orphanedHashes)) {
            return 0;
        }

        $hashIds = array_column($orphanedHashes, 'hash_id');

        return $this->deleteHashes($hashIds);
    }

    /**
     * Physically delete hash records from the database.
     * Note: Related publishes and hash_dependents will be cascade deleted.
     *
     * @param  array<int>  $hashIds
     * @return int Number of deleted records
     */
    private function deleteHashes(array $hashIds): int
    {
        if (empty($hashIds)) {
            return 0;
        }

        $hashesTable = config('change-detection.tables.hashes', 'hashes');
        $idsPlaceholder = str_repeat('?,', count($hashIds) - 1).'?';

        $sql = "
            DELETE FROM `{$hashesTable}`
            WHERE id IN ({$idsPlaceholder})
        ";

        return $this->connection->delete($sql, $hashIds);
    }

    /**
     * Build a subquery for scoped model filtering.
     * Returns the WHERE clause part for the scope.
     */
    private function buildScopeSubquery(string $modelClass, string $tableAlias, string $primaryKey, string $modelTableName): string
    {
        $model = new $modelClass;
        $scope = $model->getHashableScope();

        if (! $scope) {
            return ''; // No scope defined
        }

        // Create a query with the scope applied
        $query = $modelClass::query();
        $scope($query);

        // Get the SQL from the scoped query
        $subquerySql = $query->select($model->getKeyName())->toSql();

        // Extract the WHERE clause from the subquery
        // The subquery will be something like: "select `customer_id` from `tbl_customer` where `customer_id` < ?"
        // We need just the WHERE part
        if (preg_match('/where (.+)$/i', $subquerySql, $matches)) {
            return ' AND '.$matches[1];
        }

        return '';
    }

    /**
     * Get bindings from a scoped query for use in raw SQL.
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
}
