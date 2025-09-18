<?php

declare(strict_types=1);

namespace Ameax\LaravelChangeDetection\Services;

use Ameax\LaravelChangeDetection\Contracts\Hashable;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;

class DependencyHashCalculator
{
    private Connection $connection;
    private string $hashAlgorithm;

    public function __construct(?string $connectionName = null)
    {
        $this->connection = DB::connection($connectionName ?? config('change-detection.database_connection'));
        $this->hashAlgorithm = config('change-detection.hash_algorithm', 'md5');
    }

    public function calculate(Hashable $model): ?string
    {
        $hashesTable = config('change-detection.tables.hashes', 'hashes');
        $hashDependentsTable = config('change-detection.tables.hash_dependents', 'hash_dependents');
        $modelClass = $model->getMorphClass();
        $modelId = $model->getKey();

        // Single query to get all dependency hashes in deterministic order
        $sql = "
            SELECT GROUP_CONCAT(
                IFNULL(h.composite_hash, h.attribute_hash)
                ORDER BY hd.id, h.hashable_type, h.hashable_id
                SEPARATOR '|'
            ) as dependency_hash
            FROM `{$hashDependentsTable}` hd
            INNER JOIN `{$hashesTable}` h
                ON h.id = hd.hash_id
                AND h.deleted_at IS NULL
            WHERE hd.dependent_model_type = ?
              AND hd.dependent_model_id = ?
        ";

        $result = $this->connection->selectOne($sql, [$modelClass, $modelId]);

        if (!$result || !$result->dependency_hash) {
            return null;
        }

        return match($this->hashAlgorithm) {
            'sha256' => hash('sha256', $result->dependency_hash),
            default => md5($result->dependency_hash)
        };
    }

    public function calculateBulk(string $modelClass, array $modelIds): array
    {
        $hashesTable = config('change-detection.tables.hashes', 'hashes');
        $hashDependentsTable = config('change-detection.tables.hash_dependents', 'hash_dependents');
        $morphClass = (new $modelClass)->getMorphClass();

        $placeholders = str_repeat('?,', count($modelIds) - 1) . '?';

        $sql = "
            SELECT
                hd.dependent_model_id,
                GROUP_CONCAT(
                    IFNULL(h.composite_hash, h.attribute_hash)
                    ORDER BY hd.id, h.hashable_type, h.hashable_id
                    SEPARATOR '|'
                ) as dependency_hash
            FROM `{$hashDependentsTable}` hd
            INNER JOIN `{$hashesTable}` h
                ON h.id = hd.hash_id
                AND h.deleted_at IS NULL
            WHERE hd.dependent_model_type = ?
              AND hd.dependent_model_id IN ({$placeholders})
            GROUP BY hd.dependent_model_id
        ";

        $results = $this->connection->select($sql, array_merge([$morphClass], $modelIds));

        $hashes = [];
        foreach ($results as $result) {
            if ($result->dependency_hash) {
                $hashes[$result->dependent_model_id] = match($this->hashAlgorithm) {
                    'sha256' => hash('sha256', $result->dependency_hash),
                    default => md5($result->dependency_hash)
                };
            } else {
                $hashes[$result->dependent_model_id] = null;
            }
        }

        // Fill in nulls for models with no dependencies
        foreach ($modelIds as $modelId) {
            if (!isset($hashes[$modelId])) {
                $hashes[$modelId] = null;
            }
        }

        return $hashes;
    }

    public function getConnection(): Connection
    {
        return $this->connection;
    }

    public function getHashAlgorithm(): string
    {
        return $this->hashAlgorithm;
    }
}