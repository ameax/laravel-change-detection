<?php

declare(strict_types=1);

namespace Ameax\LaravelChangeDetection\Services;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;

class CrossDatabaseQueryBuilder
{
    private Connection $hashConnection;
    private ?string $hashConnectionName;

    public function __construct(?string $hashConnectionName = null)
    {
        $this->hashConnectionName = $hashConnectionName ?? config('change-detection.database_connection');
        $this->hashConnection = DB::connection($this->hashConnectionName);
    }

    public function buildCrossDatabaseTableName(string $modelTable, string $modelConnectionName = null): string
    {
        if ($this->hashConnectionName === $modelConnectionName || $this->hashConnectionName === null) {
            return "`{$modelTable}`";
        }

        $modelConnection = DB::connection($modelConnectionName);
        $modelDatabase = $modelConnection->getDatabaseName();

        return "`{$modelDatabase}`.`{$modelTable}`";
    }

    public function buildHashTableName(string $tableName): string
    {
        $hashDatabase = $this->hashConnection->getDatabaseName();

        if ($hashDatabase) {
            return "`{$hashDatabase}`.`{$tableName}`";
        }

        return "`{$tableName}`";
    }

    public function getModelConnection(string $modelClass): Connection
    {
        $model = new $modelClass;
        return $model->getConnection();
    }

    public function getHashConnection(): Connection
    {
        return $this->hashConnection;
    }

    public function getConnectionName(): ?string
    {
        return $this->hashConnectionName;
    }

    public function buildJoinCondition(
        string $modelTable,
        string $modelPrimaryKey,
        string $hashTable,
        string $morphClass,
        string $modelConnectionName = null
    ): array {
        $modelTableName = $this->buildCrossDatabaseTableName($modelTable, $modelConnectionName);
        $hashTableName = $this->buildHashTableName($hashTable);

        $sql = "
            LEFT JOIN {$hashTableName} h
                ON h.hashable_type = ?
                AND h.hashable_id = m.`{$modelPrimaryKey}`
                AND h.deleted_at IS NULL
        ";

        return [
            'sql' => $sql,
            'bindings' => [$morphClass],
            'model_table' => $modelTableName,
            'hash_table' => $hashTableName,
        ];
    }

    public function executeCrossDatabaseQuery(string $sql, array $bindings = []): array
    {
        // Use hash connection to execute cross-database queries
        return $this->hashConnection->select($sql, $bindings);
    }

    public function executeCrossDatabaseStatement(string $sql, array $bindings = []): bool
    {
        return $this->hashConnection->statement($sql, $bindings);
    }

    public function executeCrossDatabaseUpdate(string $sql, array $bindings = []): int
    {
        return $this->hashConnection->update($sql, $bindings);
    }
}