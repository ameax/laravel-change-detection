<?php

declare(strict_types=1);

namespace Ameax\LaravelChangeDetection\Services;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;

class HashPurger
{
    private Connection $connection;

    public function __construct(?string $connectionName = null)
    {
        $this->connection = DB::connection($connectionName ?? config('change-detection.database_connection'));
    }

    /**
     * Purge deleted hashes from the database.
     *
     * @param  int|null  $olderThanDays  Only purge hashes deleted more than X days ago
     * @return int Number of purged hash records
     */
    public function purgeDeletedHashes(?int $olderThanDays = null): int
    {
        $hashesTable = config('change-detection.tables.hashes', 'hashes');

        if ($olderThanDays !== null && $olderThanDays > 0) {
            $sql = "
                DELETE FROM `{$hashesTable}`
                WHERE deleted_at IS NOT NULL
                  AND deleted_at < DATE_SUB(NOW(), INTERVAL ? DAY)
            ";

            return $this->connection->delete($sql, [$olderThanDays]);
        }

        // Purge all deleted hashes
        return $this->purgeAllDeletedHashes();
    }

    /**
     * Purge all deleted hashes regardless of age.
     *
     * @return int Number of purged hash records
     */
    public function purgeAllDeletedHashes(): int
    {
        $hashesTable = config('change-detection.tables.hashes', 'hashes');

        $sql = "
            DELETE FROM `{$hashesTable}`
            WHERE deleted_at IS NOT NULL
        ";

        return $this->connection->delete($sql);
    }

    /**
     * Count how many hashes would be purged.
     *
     * @param  int|null  $olderThanDays  Only count hashes deleted more than X days ago
     * @return int Number of purgeable hash records
     */
    public function countPurgeable(?int $olderThanDays = null): int
    {
        $hashesTable = config('change-detection.tables.hashes', 'hashes');

        if ($olderThanDays !== null && $olderThanDays > 0) {
            $sql = "
                SELECT COUNT(*) as purgeable_count
                FROM `{$hashesTable}`
                WHERE deleted_at IS NOT NULL
                  AND deleted_at < DATE_SUB(NOW(), INTERVAL ? DAY)
            ";

            $result = $this->connection->selectOne($sql, [$olderThanDays]);
        } else {
            $sql = "
                SELECT COUNT(*) as purgeable_count
                FROM `{$hashesTable}`
                WHERE deleted_at IS NOT NULL
            ";

            $result = $this->connection->selectOne($sql);
        }

        return (int) $result->purgeable_count;
    }

    /**
     * Purge deleted hashes for specific model types.
     *
     * @param  array<string>  $morphClasses  Array of morph class names
     * @param  int|null  $olderThanDays  Only purge hashes deleted more than X days ago
     * @return int Number of purged hash records
     */
    public function purgeDeletedHashesForModels(array $morphClasses, ?int $olderThanDays = null): int
    {
        if (empty($morphClasses)) {
            return 0;
        }

        $hashesTable = config('change-detection.tables.hashes', 'hashes');
        $placeholders = str_repeat('?,', count($morphClasses) - 1).'?';

        if ($olderThanDays !== null && $olderThanDays > 0) {
            $sql = "
                DELETE FROM `{$hashesTable}`
                WHERE deleted_at IS NOT NULL
                  AND hashable_type IN ({$placeholders})
                  AND deleted_at < DATE_SUB(NOW(), INTERVAL ? DAY)
            ";

            $bindings = array_merge($morphClasses, [$olderThanDays]);
        } else {
            $sql = "
                DELETE FROM `{$hashesTable}`
                WHERE deleted_at IS NOT NULL
                  AND hashable_type IN ({$placeholders})
            ";

            $bindings = $morphClasses;
        }

        return $this->connection->delete($sql, $bindings);
    }

    /**
     * Get statistics about purgeable hashes grouped by model type.
     *
     * @param  int|null  $olderThanDays  Only count hashes deleted more than X days ago
     * @return array<int, array{model_type: string, count: int, oldest_deleted_at: string}>
     */
    public function getPurgeableStatistics(?int $olderThanDays = null): array
    {
        $hashesTable = config('change-detection.tables.hashes', 'hashes');

        if ($olderThanDays !== null && $olderThanDays > 0) {
            $sql = "
                SELECT
                    hashable_type as model_type,
                    COUNT(*) as count,
                    MIN(deleted_at) as oldest_deleted_at
                FROM `{$hashesTable}`
                WHERE deleted_at IS NOT NULL
                  AND deleted_at < DATE_SUB(NOW(), INTERVAL ? DAY)
                GROUP BY hashable_type
                ORDER BY count DESC
            ";

            $results = $this->connection->select($sql, [$olderThanDays]);
        } else {
            $sql = "
                SELECT
                    hashable_type as model_type,
                    COUNT(*) as count,
                    MIN(deleted_at) as oldest_deleted_at
                FROM `{$hashesTable}`
                WHERE deleted_at IS NOT NULL
                GROUP BY hashable_type
                ORDER BY count DESC
            ";

            $results = $this->connection->select($sql);
        }

        return array_map(function ($result) {
            return [
                'model_type' => $result->model_type,
                'count' => (int) $result->count,
                'oldest_deleted_at' => $result->oldest_deleted_at,
            ];
        }, $results);
    }

    public function getConnection(): Connection
    {
        return $this->connection;
    }
}
