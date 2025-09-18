<?php

declare(strict_types=1);

namespace Ameax\LaravelChangeDetection\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Builder;

/**
 * @property int $id
 * @property int|null $hash_id
 * @property int $publisher_id
 * @property string $published_hash
 * @property \Illuminate\Support\Carbon|null $published_at
 * @property string $status
 * @property int $attempts
 * @property string|null $last_error
 * @property \Illuminate\Support\Carbon|null $next_try
 * @property array<string, mixed>|null $metadata
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 * @property-read \Ameax\LaravelChangeDetection\Models\Hash|null $hash
 * @property-read \Ameax\LaravelChangeDetection\Models\Publisher $publisher
 */
class Publish extends Model
{
    protected $fillable = [
        'hash_id',
        'publisher_id',
        'published_hash',
        'published_at',
        'status',
        'attempts',
        'last_error',
        'next_try',
        'metadata',
    ];

    protected $casts = [
        'published_at' => 'datetime',
        'next_try' => 'datetime',
        'attempts' => 'integer',
        'status' => 'string',
        'metadata' => 'array',
    ];

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
        $this->table = config('change-detection.tables.publishes', 'publishes');

        $connection = config('change-detection.database_connection');
        if ($connection) {
            $this->connection = $connection;
        }
    }

    public function hash(): BelongsTo
    {
        return $this->belongsTo(Hash::class);
    }

    public function publisher(): BelongsTo
    {
        return $this->belongsTo(Publisher::class);
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function isDispatched(): bool
    {
        return $this->status === 'dispatched';
    }

    public function isDeferred(): bool
    {
        return $this->status === 'deferred';
    }

    public function isPublished(): bool
    {
        return $this->status === 'published';
    }

    public function isFailed(): bool
    {
        return $this->status === 'failed';
    }

    public function shouldRetry(): bool
    {
        return $this->isDeferred() &&
               $this->next_try &&
               $this->next_try->isPast();
    }

    public function markAsDispatched(): void
    {
        $this->update(['status' => 'dispatched']);
    }

    public function markAsPublished(): void
    {
        $this->update([
            'status' => 'published',
            'published_at' => now(),
            'last_error' => null,
        ]);
    }

    public function markAsFailed(string $error): void
    {
        $this->update([
            'status' => 'failed',
            'last_error' => $error,
        ]);
    }

    public function markAsDeferred(string $error): void
    {
        $this->attempts++;

        $retryIntervals = config('change-detection.retry_intervals', [
            1 => 30,
            2 => 300,
            3 => 21600,
        ]);

        if ($this->attempts > count($retryIntervals)) {
            $this->markAsFailed($error);
            return;
        }

        $this->update([
            'status' => 'deferred',
            'attempts' => $this->attempts,
            'last_error' => $error,
            'next_try' => now()->addSeconds($retryIntervals[$this->attempts]),
        ]);
    }

    public function scopePendingOrDeferred(Builder $query): Builder
    {
        return $query->where(function ($q) {
            $q->where('status', 'pending')
                ->orWhere(function ($q) {
                    $q->where('status', 'deferred')
                        ->where('next_try', '<=', now());
                });
        });
    }
}