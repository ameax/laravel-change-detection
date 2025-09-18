<?php

declare(strict_types=1);

namespace Ameax\LaravelChangeDetection\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Builder;

/**
 * @property int $id
 * @property string $hashable_type
 * @property int $hashable_id
 * @property string $attribute_hash
 * @property string|null $composite_hash
 * @property \Illuminate\Support\Carbon|null $deleted_at
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 * @property-read \Illuminate\Database\Eloquent\Model $hashable
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \Ameax\LaravelChangeDetection\Models\Publish> $publishes
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \Ameax\LaravelChangeDetection\Models\HashDependent> $dependents
 */
class Hash extends Model
{
    protected $fillable = [
        'hashable_type',
        'hashable_id',
        'attribute_hash',
        'composite_hash',
        'deleted_at',
    ];

    protected $casts = [
        'deleted_at' => 'datetime',
    ];

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
        $this->table = config('change-detection.tables.hashes', 'hashes');

        $connection = config('change-detection.database_connection');
        if ($connection) {
            $this->connection = $connection;
        }
    }

    public function hashable(): MorphTo
    {
        return $this->morphTo();
    }

    public function dependents(): HasMany
    {
        return $this->hasMany(HashDependent::class, 'hash_id');
    }

    public function publishes(): HasMany
    {
        return $this->hasMany(Publish::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('deleted_at');
    }

    public function scopeDeleted(Builder $query): Builder
    {
        return $query->whereNotNull('deleted_at');
    }

    public function markAsDeleted(): void
    {
        $this->update(['deleted_at' => now()]);
    }

    public function restore(): void
    {
        $this->update(['deleted_at' => null]);
    }

    public function isDeleted(): bool
    {
        return !is_null($this->deleted_at);
    }

    public function hasDependents(): bool
    {
        return $this->dependents()->exists();
    }

    public function hasChanged(string $newHash): bool
    {
        return $this->attribute_hash !== $newHash;
    }

    public function hasCompositeChanged(string $newCompositeHash): bool
    {
        return $this->composite_hash !== $newCompositeHash;
    }
}