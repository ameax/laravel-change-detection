<?php

declare(strict_types=1);

namespace Ameax\LaravelChangeDetection\Services;

use Ameax\LaravelChangeDetection\Contracts\Hashable;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class MySQLHashCalculator
{
    private Connection $connection;

    private string $hashAlgorithm;

    public function __construct(?string $connectionName = null)
    {
        $this->connection = DB::connection($connectionName ?? config('change-detection.database_connection'));
        $this->hashAlgorithm = config('change-detection.hash_algorithm', 'md5');
    }

    public function calculateAttributeHash(Hashable&Model $model): string
    {
        $table = $model->getTable();
        $primaryKey = $model->getKeyName();
        $attributes = $model->getHashableAttributes();
        $modelId = $model->getKey();
        $joins = $model->getHashableJoins();

        // Use the model's connection for hash calculation to support cross-database scenarios
        $modelConnection = $model->getConnection();
        $modelDatabase = $modelConnection->getDatabaseName();

        sort($attributes);

        // Build main model attributes concat parts
        // Wrap in MAX() for GROUP BY compatibility when joins are present
        $concatParts = [];
        $wrapFunction = ! empty($joins) ? 'MAX' : '';

        foreach ($attributes as $attribute) {
            $column = "IFNULL(CAST(`{$table}`.`{$attribute}` AS CHAR), '')";
            $concatParts[] = $wrapFunction ? "{$wrapFunction}({$column})" : $column;
        }

        // Add joined columns (sorted alphabetically, already fully qualified with database prefix)
        $joinedColumns = $this->buildJoinedColumns($joins, $modelDatabase);
        foreach ($joinedColumns as $qualifiedColumn) {
            $column = "IFNULL(CAST({$qualifiedColumn} AS CHAR), '')";
            $concatParts[] = $wrapFunction ? "{$wrapFunction}({$column})" : $column;
        }

        $concatExpression = 'CONCAT('.implode(", '|', ", $concatParts).')';

        $hashExpression = match ($this->hashAlgorithm) {
            'sha256' => "SHA2({$concatExpression}, 256)",
            default => "MD5({$concatExpression})"
        };

        // Build JOIN clauses with database prefixes
        $joinClauses = $this->buildJoinClauses($joins, $modelDatabase, $modelConnection);

        // Only add GROUP BY if there are joins
        $groupByClause = ! empty($joins) ? "GROUP BY `{$table}`.`{$primaryKey}`" : '';

        $sql = "
            SELECT {$hashExpression} as attribute_hash
            FROM `{$modelDatabase}`.`{$table}`
            {$joinClauses}
            WHERE `{$table}`.`{$primaryKey}` = ?
            {$groupByClause}
        ";

        $result = $modelConnection->selectOne($sql, [$modelId]);

        return $result->attribute_hash;
    }

    /**
     * @param  class-string<Hashable&Model>  $modelClass
     * @param  array<int>  $modelIds
     * @return array<int, string>
     */
    public function calculateAttributeHashBulk(string $modelClass, array $modelIds): array
    {
        /** @var Hashable&Model $model */
        $model = new $modelClass;
        $table = $model->getTable();
        $primaryKey = $model->getKeyName();
        $attributes = $model->getHashableAttributes();
        $joins = $model->getHashableJoins();

        // Use the model's connection for hash calculation to support cross-database scenarios
        $modelConnection = $model->getConnection();
        $modelDatabase = $modelConnection->getDatabaseName();

        sort($attributes);

        // Build main model attributes concat parts
        // Wrap in MAX() for GROUP BY compatibility when joins are present
        $concatParts = [];
        $wrapFunction = ! empty($joins) ? 'MAX' : '';

        foreach ($attributes as $attribute) {
            $column = "IFNULL(CAST(`{$table}`.`{$attribute}` AS CHAR), '')";
            $concatParts[] = $wrapFunction ? "{$wrapFunction}({$column})" : $column;
        }

        // Add joined columns (sorted alphabetically, already fully qualified with database prefix)
        $joinedColumns = $this->buildJoinedColumns($joins, $modelDatabase);
        foreach ($joinedColumns as $qualifiedColumn) {
            $column = "IFNULL(CAST({$qualifiedColumn} AS CHAR), '')";
            $concatParts[] = $wrapFunction ? "{$wrapFunction}({$column})" : $column;
        }

        $concatExpression = 'CONCAT('.implode(", '|', ", $concatParts).')';

        $hashExpression = match ($this->hashAlgorithm) {
            'sha256' => "SHA2({$concatExpression}, 256)",
            default => "MD5({$concatExpression})"
        };

        $placeholders = str_repeat('?,', count($modelIds) - 1).'?';

        // Build JOIN clauses with database prefixes
        $joinClauses = $this->buildJoinClauses($joins, $modelDatabase, $modelConnection);

        // Only add GROUP BY if there are joins
        $groupByClause = ! empty($joins) ? "GROUP BY `{$table}`.`{$primaryKey}`" : '';

        $sql = "
            SELECT
                `{$table}`.`{$primaryKey}` as model_id,
                {$hashExpression} as attribute_hash
            FROM `{$modelDatabase}`.`{$table}`
            {$joinClauses}
            WHERE `{$table}`.`{$primaryKey}` IN ({$placeholders})
            {$groupByClause}
        ";

        $results = $modelConnection->select($sql, $modelIds);

        return collect($results)->keyBy('model_id')->map->attribute_hash->toArray();
    }

    public function getConnection(): Connection
    {
        return $this->connection;
    }

    public function getHashAlgorithm(): string
    {
        return $this->hashAlgorithm;
    }

    /**
     * Build sorted array of joined column references with database prefix.
     *
     * @param  array<array{model: class-string<Model>, join: \Closure, columns: array<string, string>}>  $joins
     * @param  string  $database  The database name to prefix columns with
     * @return array<string>
     */
    private function buildJoinedColumns(array $joins, string $database): array
    {
        $columns = [];

        foreach ($joins as $joinConfig) {
            foreach ($joinConfig['columns'] as $alias) {
                // Convert table.column to `database`.`table`.`column`
                $parts = explode('.', $alias);
                if (count($parts) === 2) {
                    $columns[] = "`{$database}`.`{$parts[0]}`.`{$parts[1]}`";
                } else {
                    // Fallback: wrap as-is
                    $columns[] = "`{$alias}`";
                }
            }
        }

        sort($columns);

        return $columns;
    }

    /**
     * Build JOIN clauses with database prefixes.
     *
     * @param  array<array{model: class-string<Model>, join: \Closure, columns: array<string, string>}>  $joins
     */
    private function buildJoinClauses(array $joins, string $modelDatabase, Connection $modelConnection): string
    {
        if (empty($joins)) {
            return '';
        }

        $clauses = [];

        foreach ($joins as $joinConfig) {
            /** @var Model $joinModel */
            $joinModel = new $joinConfig['model'];
            $joinConnection = $joinModel->getConnection();
            $joinDatabase = $joinConnection->getDatabaseName();
            $joinTable = $joinModel->getTable();

            // Build the join clause using the closure
            $query = $modelConnection->query()->from('dummy');
            $joinConfig['join']($query);

            // Get the join clauses from the query builder
            $joinClauses = $query->joins ?? [];

            foreach ($joinClauses as $join) {
                // Extract join information
                $joinType = strtoupper($join->type);
                $actualJoinTable = $join->table;
                $first = $join->wheres[0]['first'] ?? '';
                $operator = $join->wheres[0]['operator'] ?? '=';
                $second = $join->wheres[0]['second'] ?? '';

                // Build SQL with database prefixes
                $sql = "{$joinType} JOIN `{$joinDatabase}`.`{$actualJoinTable}` ON {$first} {$operator} {$second}";
                $clauses[] = $sql;
            }
        }

        return implode(' ', $clauses);
    }
}
