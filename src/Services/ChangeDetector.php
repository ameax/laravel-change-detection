<?php

declare(strict_types=1);

namespace Ameax\LaravelChangeDetection\Services;

use Ameax\LaravelChangeDetection\Contracts\Hashable;
use Ameax\LaravelChangeDetection\Models\Hash;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Collection;

class ChangeDetector
{
    private CompositeHashCalculator $hashCalculator;
    private Connection $connection;

    public function __construct(CompositeHashCalculator $hashCalculator, ?string $connectionName = null)
    {
        $this->hashCalculator = $hashCalculator;
        $this->connection = DB::connection($connectionName ?? config('change-detection.database_connection'));
    }

    public function hasChanged(Hashable $model): bool
    {
        $calculatedHash = $this->hashCalculator->calculate($model);
        $currentHash = $this->getCurrentHash($model);

        return $currentHash !== $calculatedHash;
    }

    public function detectChangedModelIds(string $modelClass, int $limit = null): array
    {
        $model = new $modelClass;
        $table = $model->getTable();
        $primaryKey = $model->getKeyName();
        $morphClass = $model->getMorphClass();
        $attributes = $model->getHashableAttributes();
        $hashesTable = config('change-detection.tables.hashes', 'hashes');
        $hashDependentsTable = config('change-detection.tables.hash_dependents', 'hash_dependents');

        sort($attributes);

        // Build attribute hash expression
        $concatParts = [];
        foreach ($attributes as $attribute) {
            $concatParts[] = "IFNULL(CAST(m.`{$attribute}` AS CHAR), '')";
        }
        $attributeHashExpr = 'MD5(CONCAT(' . implode(", '|', ", $concatParts) . '))';

        // Build dependency hash subquery
        $dependencyHashExpr = "
            (SELECT MD5(GROUP_CONCAT(
                IFNULL(dh.composite_hash, dh.attribute_hash)
                ORDER BY dhd.id, dh.hashable_type, dh.hashable_id
                SEPARATOR '|'
            ))
            FROM `{$hashDependentsTable}` dhd
            INNER JOIN `{$hashesTable}` dh
                ON dh.id = dhd.hash_id
                AND dh.deleted_at IS NULL
            WHERE dhd.dependent_model_type = ?
              AND dhd.dependent_model_id = m.`{$primaryKey}`)
        ";

        // Build composite hash expression
        $compositeHashExpr = "MD5(CONCAT(
            {$attributeHashExpr},
            '|',
            IFNULL({$dependencyHashExpr}, '')
        ))";

        $limitClause = $limit ? "LIMIT {$limit}" : '';

        $sql = "
            SELECT m.`{$primaryKey}` as model_id
            FROM `{$table}` m
            LEFT JOIN `{$hashesTable}` h
                ON h.hashable_type = ?
                AND h.hashable_id = m.`{$primaryKey}`
                AND h.deleted_at IS NULL
            WHERE h.composite_hash IS NULL
               OR h.composite_hash != {$compositeHashExpr}
            {$limitClause}
        ";

        $results = $this->connection->select($sql, [$morphClass, $morphClass]);

        return array_column($results, 'model_id');
    }

    public function detectChangedModels(string $modelClass, int $limit = null): Collection
    {
        $changedIds = $this->detectChangedModelIds($modelClass, $limit);

        if (empty($changedIds)) {
            return collect();
        }

        return $modelClass::whereIn((new $modelClass)->getKeyName(), $changedIds)->get();
    }

    public function countChangedModels(string $modelClass): int
    {
        $model = new $modelClass;
        $table = $model->getTable();
        $primaryKey = $model->getKeyName();
        $morphClass = $model->getMorphClass();
        $attributes = $model->getHashableAttributes();
        $hashesTable = config('change-detection.tables.hashes', 'hashes');
        $hashDependentsTable = config('change-detection.tables.hash_dependents', 'hash_dependents');

        sort($attributes);

        $concatParts = [];
        foreach ($attributes as $attribute) {
            $concatParts[] = "IFNULL(CAST(m.`{$attribute}` AS CHAR), '')";
        }
        $attributeHashExpr = 'MD5(CONCAT(' . implode(", '|', ", $concatParts) . '))';

        $dependencyHashExpr = "
            (SELECT MD5(GROUP_CONCAT(
                IFNULL(dh.composite_hash, dh.attribute_hash)
                ORDER BY dhd.id, dh.hashable_type, dh.hashable_id
                SEPARATOR '|'
            ))
            FROM `{$hashDependentsTable}` dhd
            INNER JOIN `{$hashesTable}` dh
                ON dh.id = dhd.hash_id
                AND dh.deleted_at IS NULL
            WHERE dhd.dependent_model_type = ?
              AND dhd.dependent_model_id = m.`{$primaryKey}`)
        ";

        $compositeHashExpr = "MD5(CONCAT(
            {$attributeHashExpr},
            '|',
            IFNULL({$dependencyHashExpr}, '')
        ))";

        $sql = "
            SELECT COUNT(*) as changed_count
            FROM `{$table}` m
            LEFT JOIN `{$hashesTable}` h
                ON h.hashable_type = ?
                AND h.hashable_id = m.`{$primaryKey}`
                AND h.deleted_at IS NULL
            WHERE h.composite_hash IS NULL
               OR h.composite_hash != {$compositeHashExpr}
        ";

        $result = $this->connection->selectOne($sql, [$morphClass, $morphClass]);

        return (int) $result->changed_count;
    }

    private function getCurrentHash(Hashable $model): ?string
    {
        $hash = Hash::where('hashable_type', $model->getMorphClass())
                   ->where('hashable_id', $model->getKey())
                   ->active()
                   ->first();

        return $hash?->composite_hash;
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