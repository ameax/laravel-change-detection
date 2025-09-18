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

        sort($attributes);

        $concatParts = [];
        foreach ($attributes as $attribute) {
            $concatParts[] = "IFNULL(CAST(`{$attribute}` AS CHAR), '')";
        }
        $concatExpression = 'CONCAT(' . implode(", '|', ", $concatParts) . ')';

        $hashExpression = match($this->hashAlgorithm) {
            'sha256' => "SHA2({$concatExpression}, 256)",
            default => "MD5({$concatExpression})"
        };

        $sql = "
            SELECT {$hashExpression} as attribute_hash
            FROM `{$table}`
            WHERE `{$primaryKey}` = ?
        ";

        $result = $this->connection->selectOne($sql, [$modelId]);

        return $result->attribute_hash;
    }

    /**
     * @param class-string<Hashable&Model> $modelClass
     * @param array<int> $modelIds
     * @return array<int, string>
     */
    public function calculateAttributeHashBulk(string $modelClass, array $modelIds): array
    {
        /** @var Hashable&Model $model */
        $model = new $modelClass;
        $table = $model->getTable();
        $primaryKey = $model->getKeyName();
        $attributes = $model->getHashableAttributes();

        sort($attributes);

        $concatParts = [];
        foreach ($attributes as $attribute) {
            $concatParts[] = "IFNULL(CAST(`{$attribute}` AS CHAR), '')";
        }
        $concatExpression = 'CONCAT(' . implode(", '|', ", $concatParts) . ')';

        $hashExpression = match($this->hashAlgorithm) {
            'sha256' => "SHA2({$concatExpression}, 256)",
            default => "MD5({$concatExpression})"
        };

        $placeholders = str_repeat('?,', count($modelIds) - 1) . '?';

        $sql = "
            SELECT
                `{$primaryKey}` as model_id,
                {$hashExpression} as attribute_hash
            FROM `{$table}`
            WHERE `{$primaryKey}` IN ({$placeholders})
        ";

        $results = $this->connection->select($sql, $modelIds);

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
}