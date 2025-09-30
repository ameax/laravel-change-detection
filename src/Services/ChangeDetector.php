<?php

declare(strict_types=1);

namespace Ameax\LaravelChangeDetection\Services;

use Ameax\LaravelChangeDetection\Contracts\Hashable;
use Ameax\LaravelChangeDetection\Models\Hash;
use Illuminate\Database\Connection;
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

    /**
     * @param  Hashable&\Illuminate\Database\Eloquent\Model  $model
     */
    public function hasChanged(Hashable $model): bool
    {
        /** @var Hashable&\Illuminate\Database\Eloquent\Model $model */
        $calculatedHash = $this->hashCalculator->calculate($model);
        $currentHash = $this->getCurrentHash($model);

        return $currentHash !== $calculatedHash;
    }

    /**
     * @param  class-string<\Illuminate\Database\Eloquent\Model&\Ameax\LaravelChangeDetection\Contracts\Hashable>  $modelClass
     * @return array<int>
     */
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

    /**
     * @param  class-string<\Illuminate\Database\Eloquent\Model&\Ameax\LaravelChangeDetection\Contracts\Hashable>  $modelClass
     * @return \Illuminate\Database\Eloquent\Collection<int, \Illuminate\Database\Eloquent\Model&\Ameax\LaravelChangeDetection\Contracts\Hashable>
     */
    public function detectChangedModels(string $modelClass, ?int $limit = null): \Illuminate\Database\Eloquent\Collection
    {
        $changedIds = $this->detectChangedModelIds($modelClass, $limit);

        if (empty($changedIds)) {
            /** @var \Illuminate\Database\Eloquent\Collection<int, \Illuminate\Database\Eloquent\Model&\Ameax\LaravelChangeDetection\Contracts\Hashable> */
            $emptyCollection = new \Illuminate\Database\Eloquent\Collection;

            return $emptyCollection;
        }

        /** @var \Illuminate\Database\Eloquent\Model&\Ameax\LaravelChangeDetection\Contracts\Hashable $tempModel */
        $tempModel = new $modelClass;
        $query = $modelClass::whereIn($tempModel->getKeyName(), $changedIds);

        // Apply scope if defined
        $model = new $modelClass;
        $scope = $model->getHashableScope();
        if ($scope) {
            $scope($query);
        }

        /** @var \Illuminate\Database\Eloquent\Collection<int, \Illuminate\Database\Eloquent\Model&\Ameax\LaravelChangeDetection\Contracts\Hashable> */
        $result = $query->get();

        return $result;
    }

    /**
     * @param  class-string<\Illuminate\Database\Eloquent\Model&\Ameax\LaravelChangeDetection\Contracts\Hashable>  $modelClass
     */
    public function countChangedModels(string $modelClass, ?int $limit = null): int
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

    /**
     * @param  Hashable&\Illuminate\Database\Eloquent\Model  $model
     */
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
     *
     * @param  class-string<\Illuminate\Database\Eloquent\Model&\Ameax\LaravelChangeDetection\Contracts\Hashable>  $modelClass
     */
    private function buildScopeSubquery(string $modelClass, string $tableAlias, string $primaryKey): string
    {
        $model = new $modelClass;
        $scope = $model->getHashableScope();
        $parentRelations = $model->getHashParentRelations();

        $clauses = [];

        // Add own scope if exists
        if ($scope) {
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

            $clauses[] = "{$tableAlias}.`{$primaryKey}` IN ({$subquerySql})";
        }

        // Add parent scope if has parent relations
        if (! empty($parentRelations)) {
            $parentClause = $this->buildParentScopeSubquery($model, $tableAlias, $primaryKey, $parentRelations);
            if ($parentClause) {
                // Remove leading " AND " from parent clause
                $parentClause = ltrim($parentClause, ' AND ');
                $clauses[] = $parentClause;
            }
        }

        if (empty($clauses)) {
            return ''; // No filtering needed
        }

        // If only one clause, return it as-is (no extra wrapping)
        if (count($clauses) === 1) {
            return ' AND '.$clauses[0];
        }

        // Multiple clauses: combine with AND and wrap in parentheses
        return ' AND ('.implode(' AND ', $clauses).')';
    }

    /**
     * Build a subquery that filters child models by their parent's scope.
     *
     * @param  \Ameax\LaravelChangeDetection\Contracts\Hashable&\Illuminate\Database\Eloquent\Model  $model
     * @param  array<string>  $parentRelations
     */
    private function buildParentScopeSubquery(\Ameax\LaravelChangeDetection\Contracts\Hashable $model, string $tableAlias, string $primaryKey, array $parentRelations): string
    {
        $conditions = [];

        foreach ($parentRelations as $relationName) {
            if (! method_exists($model, $relationName)) {
                continue;
            }

            try {
                $relation = $model->{$relationName}();

                // Only handle BelongsTo relations for now
                if (! ($relation instanceof \Illuminate\Database\Eloquent\Relations\BelongsTo)) {
                    continue;
                }

                $parentModel = $relation->getRelated();
                if (! ($parentModel instanceof \Ameax\LaravelChangeDetection\Contracts\Hashable)) {
                    continue;
                }

                $parentScope = $parentModel->getHashableScope();
                if (! $parentScope) {
                    // Parent has no scope, so all children of this parent are valid
                    return ''; // No filtering needed if any parent has no scope
                }

                // Get foreign key and owner key
                $foreignKey = $relation->getForeignKeyName();
                $ownerKey = $relation->getOwnerKeyName();

                // Build parent scope subquery
                $parentClass = get_class($parentModel);
                $parentQuery = $parentClass::query();
                $parentScope($parentQuery);

                $parentSubquerySql = $parentQuery->select($ownerKey)->toSql();

                // Build qualified parent table name
                $parentConnection = $parentModel->getConnection();
                $parentDatabase = $parentConnection->getDatabaseName();
                $parentTable = $parentModel->getTable();

                if ($parentDatabase) {
                    $qualifiedParentTable = "`{$parentDatabase}`.`{$parentTable}`";
                    $parentSubquerySql = str_replace("`{$parentTable}`", $qualifiedParentTable, $parentSubquerySql);
                }

                // Add condition for this parent relation
                $conditions[] = "{$tableAlias}.`{$foreignKey}` IN ({$parentSubquerySql})";
            } catch (\Exception $e) {
                \Illuminate\Support\Facades\Log::warning(
                    "Failed to build parent scope subquery for relation {$relationName}",
                    ['error' => $e->getMessage()]
                );
            }
        }

        if (empty($conditions)) {
            return ''; // No valid parent relations with scope
        }

        // Use OR if multiple parent relations (child is valid if ANY parent is in scope)
        return ' AND ('.implode(' OR ', $conditions).')';
    }

    /**
     * Get bindings from a scoped query for use in raw SQL.
     * Collects bindings from both own scope and parent scope.
     *
     * @param  class-string<\Illuminate\Database\Eloquent\Model&\Ameax\LaravelChangeDetection\Contracts\Hashable>  $modelClass
     * @return array<mixed>
     */
    private function getScopeBindings(string $modelClass): array
    {
        $model = new $modelClass;
        $scope = $model->getHashableScope();
        $parentRelations = $model->getHashParentRelations();

        $bindings = [];

        // Collect own scope bindings
        if ($scope) {
            $query = $modelClass::query();
            $scope($query);
            $bindings = array_merge($bindings, $query->getBindings());
        }

        // Collect parent scope bindings
        if (! empty($parentRelations)) {
            $parentBindings = $this->getParentScopeBindings($model, $parentRelations);
            $bindings = array_merge($bindings, $parentBindings);
        }

        return $bindings;
    }

    /**
     * Get bindings from parent scope queries.
     *
     * @param  \Ameax\LaravelChangeDetection\Contracts\Hashable&\Illuminate\Database\Eloquent\Model  $model
     * @param  array<string>  $parentRelations
     * @return array<mixed>
     */
    private function getParentScopeBindings(\Ameax\LaravelChangeDetection\Contracts\Hashable $model, array $parentRelations): array
    {
        $bindings = [];

        foreach ($parentRelations as $relationName) {
            if (! method_exists($model, $relationName)) {
                continue;
            }

            try {
                $relation = $model->{$relationName}();
                if (! ($relation instanceof \Illuminate\Database\Eloquent\Relations\BelongsTo)) {
                    continue;
                }

                $parentModel = $relation->getRelated();
                if (! ($parentModel instanceof \Ameax\LaravelChangeDetection\Contracts\Hashable)) {
                    continue;
                }

                $parentScope = $parentModel->getHashableScope();
                if (! $parentScope) {
                    return []; // No scope on any parent means no bindings needed
                }

                $parentClass = get_class($parentModel);
                $parentQuery = $parentClass::query();
                $parentScope($parentQuery);

                $bindings = array_merge($bindings, $parentQuery->getBindings());
            } catch (\Exception $e) {
                // Skip this relation
            }
        }

        return $bindings;
    }
}
