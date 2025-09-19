<?php

declare(strict_types=1);

namespace Ameax\LaravelChangeDetection\Services;

use Ameax\LaravelChangeDetection\Contracts\Hashable;
use Ameax\LaravelChangeDetection\Models\Hash;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ChangeDetector
{
    private CompositeHashCalculator $hashCalculator;

    private Connection $connection;

    private CrossDatabaseQueryBuilder $crossDbBuilder;

    public function __construct(
        CompositeHashCalculator $hashCalculator,
        ?CrossDatabaseQueryBuilder $crossDbBuilder = null,
        ?string $connectionName = null
    ) {
        $this->hashCalculator = $hashCalculator;
        $this->connection = DB::connection($connectionName ?? config('change-detection.database_connection'));
        $this->crossDbBuilder = $crossDbBuilder ?? new CrossDatabaseQueryBuilder($connectionName);
    }

    public function hasChanged(Hashable $model): bool
    {
        $calculatedHash = $this->hashCalculator->calculate($model);
        $currentHash = $this->getCurrentHash($model);

        return $currentHash !== $calculatedHash;
    }

    public function detectChangedModelIds(string $modelClass, ?int $limit = null): array
    {
        $model = new $modelClass;
        $table = $model->getTable();
        $primaryKey = $model->getKeyName();
        $morphClass = $model->getMorphClass();
        $attributes = $model->getHashableAttributes();
        $modelConnectionName = $model->getConnectionName();

        $hashesTable = config('change-detection.tables.hashes', 'hashes');
        $hashDependentsTable = config('change-detection.tables.hash_dependents', 'hash_dependents');

        sort($attributes);

        // Build cross-database table names
        $modelTableName = $this->crossDbBuilder->buildCrossDatabaseTableName($table, $modelConnectionName);
        $hashesTableName = $this->crossDbBuilder->buildHashTableName($hashesTable);
        $hashDependentsTableName = $this->crossDbBuilder->buildHashTableName($hashDependentsTable);

        // Build attribute hash expression
        $concatParts = [];
        foreach ($attributes as $attribute) {
            $concatParts[] = "IFNULL(CAST(m.`{$attribute}` AS CHAR), '')";
        }
        $attributeHashExpr = 'MD5(CONCAT('.implode(", '|', ", $concatParts).'))';

        // Build dependency hash subquery
        $dependencyHashExpr = "
            (SELECT MD5(GROUP_CONCAT(
                IFNULL(dh.composite_hash, dh.attribute_hash)
                ORDER BY dhd.id, dh.hashable_type, dh.hashable_id
                SEPARATOR '|'
            ))
            FROM {$hashDependentsTableName} dhd
            INNER JOIN {$hashesTableName} dh
                ON dh.id = dhd.hash_id
                AND dh.deleted_at IS NULL
            WHERE dhd.dependent_model_type = '{$morphClass}'
              AND dhd.dependent_model_id = m.`{$primaryKey}`)
        ";

        // Build composite hash expression (same logic as CompositeHashCalculator)
        $compositeHashExpr = "
            CASE
                WHEN {$dependencyHashExpr} IS NULL THEN {$attributeHashExpr}
                ELSE MD5(CONCAT({$attributeHashExpr}, '|', {$dependencyHashExpr}))
            END
        ";

        // Add scope filtering
        $scopeClause = $this->buildScopeSubquery($modelClass, 'm', $primaryKey);
        $scopeBindings = $this->getScopeBindings($modelClass);

        $limitClause = $limit ? "LIMIT {$limit}" : '';

        $sql = "
            SELECT m.`{$primaryKey}` as model_id
            FROM {$modelTableName} m
            LEFT JOIN {$hashesTableName} h
                ON h.hashable_type = ?
                AND h.hashable_id = m.`{$primaryKey}`
                AND h.deleted_at IS NULL
            WHERE (h.composite_hash IS NULL
               OR h.composite_hash != {$compositeHashExpr})
            {$scopeClause}
            {$limitClause}
        ";

        $bindings = array_merge([$morphClass], $scopeBindings);
        $results = $this->crossDbBuilder->executeCrossDatabaseQuery($sql, $bindings);

        return array_column($results, 'model_id');
    }

    public function detectChangedModels(string $modelClass, ?int $limit = null): Collection
    {
        $changedIds = $this->detectChangedModelIds($modelClass, $limit);

        if (empty($changedIds)) {
            return collect();
        }

        $query = $modelClass::whereIn((new $modelClass)->getKeyName(), $changedIds);

        // Apply scope if defined
        $model = new $modelClass;
        $scope = $model->getHashableScope();
        if ($scope) {
            $scope($query);
        }

        return $query->get();
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
        $attributeHashExpr = 'MD5(CONCAT('.implode(", '|', ", $concatParts).'))';

        // Get the hash database name for qualified table names
        $hashDatabase = $this->connection->getDatabaseName();
        $qualifiedHashesTable = $hashDatabase ? "`{$hashDatabase}`.`{$hashesTable}`" : "`{$hashesTable}`";
        $qualifiedHashDependentsTable = $hashDatabase ? "`{$hashDatabase}`.`{$hashDependentsTable}`" : "`{$hashDependentsTable}`";

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

        // Get the model's database name for cross-database query
        $modelConnection = $model->getConnection();
        $modelDatabase = $modelConnection->getDatabaseName();
        $qualifiedTable = $modelDatabase ? "`{$modelDatabase}`.`{$table}`" : "`{$table}`";

        // Add scope filtering
        $scopeClause = $this->buildScopeSubquery($modelClass, 'm', $primaryKey);
        $scopeBindings = $this->getScopeBindings($modelClass);

        $sql = "
            SELECT COUNT(*) as changed_count
            FROM {$qualifiedTable} m
            LEFT JOIN {$qualifiedHashesTable} h
                ON h.hashable_type = ?
                AND h.hashable_id = m.`{$primaryKey}`
                AND h.deleted_at IS NULL
            WHERE (h.composite_hash IS NULL
               OR h.composite_hash != {$compositeHashExpr})
            {$scopeClause}
        ";

        $bindings = array_merge([$morphClass], $scopeBindings);
        $result = $this->connection->selectOne($sql, $bindings);

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

    /**
     * Build a subquery for scoped model filtering.
     * Returns empty string if no scope is defined.
     */
    private function buildScopeSubquery(string $modelClass, string $tableAlias, string $primaryKey): string
    {
        $model = new $modelClass;
        $scope = $model->getHashableScope();

        if (!$scope) {
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
     */
    private function getScopeBindings(string $modelClass): array
    {
        $model = new $modelClass;
        $scope = $model->getHashableScope();

        if (!$scope) {
            return [];
        }

        $query = $modelClass::query();
        $scope($query);

        return $query->getBindings();
    }
}
