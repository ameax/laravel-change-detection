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

        $hashesTable = config('change-detection.tables.hashes', 'hashes');

        $modelTableName = $this->crossDbBuilder->buildCrossDatabaseTableName($table, $modelConnectionName);
        $hashesTableName = $this->crossDbBuilder->buildHashTableName($hashesTable);

        $limitClause = $limit ? "LIMIT {$limit}" : '';

        $sql = "
            SELECT h.id as hash_id, h.hashable_id as model_id
            FROM {$hashesTableName} h
            LEFT JOIN {$modelTableName} m ON m.`{$primaryKey}` = h.hashable_id
            WHERE h.hashable_type = ?
              AND h.deleted_at IS NULL
              AND m.`{$primaryKey}` IS NULL
            {$limitClause}
        ";

        $results = $this->crossDbBuilder->executeCrossDatabaseQuery($sql, [$morphClass]);

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

        $hashesTable = config('change-detection.tables.hashes', 'hashes');

        $modelTableName = $this->crossDbBuilder->buildCrossDatabaseTableName($table, $modelConnectionName);
        $hashesTableName = $this->crossDbBuilder->buildHashTableName($hashesTable);

        $sql = "
            SELECT COUNT(*) as orphaned_count
            FROM {$hashesTableName} h
            LEFT JOIN {$modelTableName} m ON m.`{$primaryKey}` = h.hashable_id
            WHERE h.hashable_type = ?
              AND h.deleted_at IS NULL
              AND m.`{$primaryKey}` IS NULL
        ";

        $result = $this->crossDbBuilder->executeCrossDatabaseQuery($sql, [$morphClass]);

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
}
