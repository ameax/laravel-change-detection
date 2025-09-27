<?php

declare(strict_types=1);

namespace Ameax\LaravelChangeDetection\Models;

use Ameax\LaravelChangeDetection\Enums\PublisherStatusEnum;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $name
 * @property string $model_type
 * @property string $publisher_class
 * @property PublisherStatusEnum $status
 * @property array<string, mixed>|null $config
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 */
class Publisher extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'model_type',
        'publisher_class',
        'status',
        'config',
    ];

    protected $casts = [
        'status' => PublisherStatusEnum::class,
        'config' => 'array',
    ];

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
        $this->table = config('change-detection.tables.publishers', 'publishers');

        $connection = config('change-detection.database_connection');
        if ($connection) {
            $this->connection = $connection;
        }
    }

    public function publishes(): HasMany
    {
        return $this->hasMany(Publish::class);
    }

    public function isActive(): bool
    {
        return $this->status === PublisherStatusEnum::ACTIVE;
    }

    /**
     * @param  Builder<Publisher>  $query
     * @return Builder<Publisher>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', PublisherStatusEnum::ACTIVE->value);
    }

    /**
     * @param  Builder<Publisher>  $query
     * @return Builder<Publisher>
     */
    public function scopeForModel(Builder $query, string $modelType): Builder
    {
        return $query->where('model_type', $modelType);
    }

    public function getPublisherInstance(): object
    {
        return app($this->publisher_class);
    }
}
