<?php

declare(strict_types=1);

namespace Ameax\LaravelChangeDetection\Services;

use Ameax\LaravelChangeDetection\Models\Publisher;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;

class BulkPublishProcessor
{
    private Connection $connection;

    public function __construct(?string $connectionName = null)
    {
        $this->connection = DB::connection($connectionName ?? config('change-detection.database_connection'));
    }

    /**
     * Synchronize ALL publish records for a model class.
     * This will:
     * 1. Create missing publish records for active publishers
     * 2. Update outdated publish records (when hash has changed)
     *
     * @param  class-string<\Illuminate\Database\Eloquent\Model&\Ameax\LaravelChangeDetection\Contracts\Hashable>  $modelClass
     * @return array{created: int, updated: int}
     */
    public function syncAllPublishRecords(string $modelClass): array
    {
        $model = new $modelClass;
        $morphClass = $model->getMorphClass();

        // Get all active publishers for this model type
        $publishers = Publisher::where('model_type', $morphClass)
            ->where('status', 'active')
            ->get();

        if ($publishers->isEmpty()) {
            return ['created' => 0, 'updated' => 0];
        }

        $created = 0;
        $updated = 0;

        foreach ($publishers as $publisher) {
            // Create missing publish records with a single INSERT...SELECT
            $created += $this->createMissingPublishRecords($morphClass, $publisher);

            // Update outdated publish records with a single UPDATE...JOIN
            $updated += $this->updateOutdatedPublishRecords($morphClass, $publisher);
        }

        return ['created' => $created, 'updated' => $updated];
    }

    /**
     * Synchronize publish records for models that have recently changed.
     * Uses a subquery to find changed models instead of passing IDs.
     *
     * @param  class-string<\Illuminate\Database\Eloquent\Model&\Ameax\LaravelChangeDetection\Contracts\Hashable>  $modelClass
     * @param  int|null  $limit  Limit the number of models to process
     * @return array{created: int, updated: int}
     */
    public function syncChangedPublishRecords(string $modelClass, ?int $limit = null): array
    {
        $model = new $modelClass;
        $morphClass = $model->getMorphClass();
        $table = $model->getTable();
        $primaryKey = $model->getKeyName();

        // Get all active publishers for this model type
        $publishers = Publisher::where('model_type', $morphClass)
            ->where('status', 'active')
            ->get();

        if ($publishers->isEmpty()) {
            return ['created' => 0, 'updated' => 0];
        }

        $hashesTable = config('change-detection.tables.hashes', 'hashes');
        $publishesTable = config('change-detection.tables.publishes', 'publishes');

        // Get model's database for cross-database queries
        $modelConnection = $model->getConnection();
        $modelDatabase = $modelConnection->getDatabaseName();
        $qualifiedTable = $modelDatabase ? "`{$modelDatabase}`.`{$table}`" : "`{$table}`";

        $created = 0;
        $updated = 0;

        foreach ($publishers as $publisher) {
            // Build subquery for changed models
            $limitClause = $limit ? "LIMIT {$limit}" : '';

            // Create publish records for changed models that don't have them
            $sql = "
                INSERT INTO `{$publishesTable}` (
                    hash_id,
                    publisher_id,
                    status,
                    attempts,
                    created_at,
                    updated_at,
                    metadata
                )
                SELECT
                    h.id as hash_id,
                    ? as publisher_id,
                    'pending' as status,
                    0 as attempts,
                    NOW() as created_at,
                    NOW() as updated_at,
                    JSON_OBJECT('model_type', ?, 'model_id', h.hashable_id) as metadata
                FROM `{$hashesTable}` h
                INNER JOIN (
                    SELECT m.`{$primaryKey}` as id
                    FROM {$qualifiedTable} m
                    INNER JOIN `{$hashesTable}` h2
                        ON h2.hashable_type = ?
                        AND h2.hashable_id = m.`{$primaryKey}`
                        AND h2.deleted_at IS NULL
                    WHERE h2.updated_at >= DATE_SUB(NOW(), INTERVAL 5 MINUTE)
                    {$limitClause}
                ) changed_models ON h.hashable_id = changed_models.id
                LEFT JOIN `{$publishesTable}` p
                    ON p.hash_id = h.id
                    AND p.publisher_id = ?
                WHERE h.hashable_type = ?
                  AND h.deleted_at IS NULL
                  AND p.id IS NULL
            ";

            $bindings = [
                $publisher->id,
                $morphClass,
                $morphClass,
                $publisher->id,
                $morphClass,
            ];

            $created += $this->connection->affectingStatement($sql, $bindings);

            // Update outdated publish records for changed models
            $sql = "
                UPDATE `{$publishesTable}` p
                INNER JOIN `{$hashesTable}` h ON h.id = p.hash_id
                INNER JOIN (
                    SELECT m.`{$primaryKey}` as id
                    FROM {$qualifiedTable} m
                    INNER JOIN `{$hashesTable}` h2
                        ON h2.hashable_type = ?
                        AND h2.hashable_id = m.`{$primaryKey}`
                        AND h2.deleted_at IS NULL
                    WHERE h2.updated_at >= DATE_SUB(NOW(), INTERVAL 5 MINUTE)
                    {$limitClause}
                ) changed_models ON h.hashable_id = changed_models.id
                SET
                    p.status = 'pending',
                    p.attempts = 0,
                    p.last_error = NULL,
                    p.last_response_code = NULL,
                    p.error_type = NULL,
                    p.next_try = NULL,
                    p.updated_at = NOW()
                WHERE p.publisher_id = ?
                  AND h.hashable_type = ?
                  AND h.deleted_at IS NULL
                  AND (
                      (p.status = 'published' AND p.published_hash != h.composite_hash)
                      OR
                      p.status = 'failed'
                  )
            ";

            $bindings = [
                $morphClass,
                $publisher->id,
                $morphClass,
            ];

            $updated += $this->connection->update($sql, $bindings);
        }

        return ['created' => $created, 'updated' => $updated];
    }

    /**
     * Create publish records for models that have hashes but no publish records.
     * Uses INSERT...SELECT for bulk creation.
     */
    private function createMissingPublishRecords(string $morphClass, Publisher $publisher): int
    {
        $hashesTable = config('change-detection.tables.hashes', 'hashes');
        $publishesTable = config('change-detection.tables.publishes', 'publishes');

        // Create all missing publish records in a single query
        $sql = "
            INSERT INTO `{$publishesTable}` (
                hash_id,
                publisher_id,
                status,
                attempts,
                created_at,
                updated_at,
                metadata
            )
            SELECT
                h.id as hash_id,
                ? as publisher_id,
                'pending' as status,
                0 as attempts,
                NOW() as created_at,
                NOW() as updated_at,
                JSON_OBJECT('model_type', ?, 'model_id', h.hashable_id) as metadata
            FROM `{$hashesTable}` h
            LEFT JOIN `{$publishesTable}` p
                ON p.hash_id = h.id
                AND p.publisher_id = ?
            WHERE h.hashable_type = ?
              AND h.deleted_at IS NULL
              AND p.id IS NULL
        ";

        $bindings = [
            $publisher->id,
            $morphClass,
            $publisher->id,
            $morphClass,
        ];

        return $this->connection->affectingStatement($sql, $bindings);
    }

    /**
     * Update publish records when the underlying hash has changed.
     * Uses UPDATE...JOIN for bulk updates.
     */
    private function updateOutdatedPublishRecords(string $morphClass, Publisher $publisher): int
    {
        $hashesTable = config('change-detection.tables.hashes', 'hashes');
        $publishesTable = config('change-detection.tables.publishes', 'publishes');

        // Update all outdated publish records in a single query
        $sql = "
            UPDATE `{$publishesTable}` p
            INNER JOIN `{$hashesTable}` h ON h.id = p.hash_id
            SET
                p.status = 'pending',
                p.attempts = 0,
                p.last_error = NULL,
                p.last_response_code = NULL,
                p.error_type = NULL,
                p.next_try = NULL,
                p.updated_at = NOW()
            WHERE p.publisher_id = ?
              AND h.hashable_type = ?
              AND h.deleted_at IS NULL
              AND (
                  (p.status = 'published' AND p.published_hash != h.composite_hash)
                  OR
                  p.status = 'failed'
              )
        ";

        return $this->connection->update($sql, [$publisher->id, $morphClass]);
    }
}
