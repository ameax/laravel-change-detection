<?php

declare(strict_types=1);

namespace Ameax\LaravelChangeDetection\Models;

use Ameax\LaravelChangeDetection\Enums\PublishErrorTypeEnum;
use Ameax\LaravelChangeDetection\Enums\PublishStatusEnum;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int|null $hash_id
 * @property int $publisher_id
 * @property string|null $published_hash
 * @property \Illuminate\Support\Carbon|null $published_at
 * @property PublishStatusEnum $status
 * @property int $attempts
 * @property string|null $last_error
 * @property int|null $last_response_code
 * @property PublishErrorTypeEnum|null $error_type
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
        'last_response_code',
        'error_type',
        'next_try',
        'metadata',
    ];

    protected $casts = [
        'published_at' => 'datetime',
        'next_try' => 'datetime',
        'attempts' => 'integer',
        'last_response_code' => 'integer',
        'status' => PublishStatusEnum::class,
        'error_type' => PublishErrorTypeEnum::class,
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
        return $this->status === PublishStatusEnum::PENDING;
    }

    public function isDispatched(): bool
    {
        return $this->status === PublishStatusEnum::DISPATCHED;
    }

    public function isDeferred(): bool
    {
        return $this->status === PublishStatusEnum::DEFERRED;
    }

    public function isPublished(): bool
    {
        return $this->status === PublishStatusEnum::PUBLISHED;
    }

    public function isFailed(): bool
    {
        return $this->status === PublishStatusEnum::FAILED;
    }

    public function isSoftDeleted(): bool
    {
        return $this->status === PublishStatusEnum::SOFT_DELETED;
    }

    public function shouldRetry(): bool
    {
        return $this->status === PublishStatusEnum::DEFERRED &&
               $this->next_try &&
               $this->next_try->isPast();
    }

    /**
     * Mark publish record as dispatched (for testing/manual status changes).
     *
     * NOTE: This does NOT increment the attempts counter.
     * For real publishing that tracks attempts, use publishNow() instead.
     */
    public function markAsDispatched(): void
    {
        $this->update([
            'status' => PublishStatusEnum::DISPATCHED,
        ]);
    }

    public function markAsPublished(): void
    {
        $this->update([
            'status' => PublishStatusEnum::PUBLISHED,
            'published_hash' => $this->hash->composite_hash,
            'published_at' => now(),
            'last_error' => null,
        ]);
    }

    public function markAsDeferred(string $error, ?int $responseCode = null, PublishErrorTypeEnum|string|null $errorType = null): void
    {
        // Increment attempts counter
        $currentAttempts = $this->attempts + 1;

        // Convert string error type to enum if needed
        if (is_string($errorType)) {
            $errorType = PublishErrorTypeEnum::tryFrom($errorType);
        }

        // Get retry intervals from publisher if available, otherwise use config
        $retryIntervals = $this->getPublisherRetryIntervals();

        if ($currentAttempts > count($retryIntervals)) {
            $this->update([
                'status' => PublishStatusEnum::FAILED,
                'attempts' => $currentAttempts,
                'last_error' => $error,
                'last_response_code' => $responseCode,
                'error_type' => $errorType,
                'next_try' => null,
            ]);

            return;
        }

        $this->update([
            'status' => PublishStatusEnum::DEFERRED,
            'attempts' => $currentAttempts,
            'last_error' => $error,
            'last_response_code' => $responseCode,
            'error_type' => $errorType,
            'next_try' => now()->addSeconds($retryIntervals[$currentAttempts]),
        ]);
    }

    /**
     * Mark publish record as failed (terminal state).
     *
     * NOTE: This does NOT increment the attempts counter.
     * This method is used when marking a record as permanently failed.
     * If called after a real publish attempt (publishNow/BulkPublishJob),
     * the attempts counter will already have been incremented.
     *
     * @param  string  $error  The error message
     * @param  int|null  $responseCode  Optional HTTP response code
     * @param  PublishErrorTypeEnum|string|null  $errorType  Optional error type for categorization
     */
    public function markAsFailed(string $error, ?int $responseCode = null, PublishErrorTypeEnum|string|null $errorType = null): void
    {
        // Convert string error type to enum if needed
        if (is_string($errorType)) {
            $errorType = PublishErrorTypeEnum::tryFrom($errorType);
        }

        $this->update([
            'status' => PublishStatusEnum::FAILED,
            'last_error' => $error,
            'last_response_code' => $responseCode,
            'error_type' => $errorType,
        ]);
    }

    /**
     * @return array<int, int>
     */
    private function getPublisherRetryIntervals(): array
    {
        try {
            /** @phpstan-ignore-next-line */
            if (! $this->publisher || ! $this->publisher->publisher_class) {
                return config('change-detection.retry_intervals', [
                    1 => 30,
                    2 => 300,
                    3 => 21600,
                ]);
            }

            $publisherClass = $this->publisher->publisher_class;
            if (! class_exists($publisherClass)) {
                return config('change-detection.retry_intervals', [
                    1 => 30,
                    2 => 300,
                    3 => 21600,
                ]);
            }

            $publisher = app($publisherClass);

            return $publisher->getRetryIntervals();
        } catch (\Exception $e) {
            return config('change-detection.retry_intervals', [
                1 => 30,
                2 => 300,
                3 => 21600,
            ]);
        }
    }

    /**
     * @param  Builder<Publish>  $query
     * @return Builder<Publish>
     */
    public function scopePendingOrDeferred(Builder $query): Builder
    {
        return $query->where(function ($q) {
            $q->where('status', PublishStatusEnum::PENDING)
                ->orWhere(function ($q) {
                    $q->where('status', PublishStatusEnum::DEFERRED)
                        ->where('next_try', '<=', now());
                });
        });
    }

    /**
     * Publish this record immediately (synchronously)
     */
    public function publishNow(): bool
    {
        // Skip soft-deleted publishes
        if ($this->isSoftDeleted()) {
            return false;
        }

        if (! $this->isPending() && ! $this->shouldRetry()) {
            return false;
        }

        if (! $this->hash) {
            $this->markAsFailed('No hash found', null);

            return false;
        }

        // Check if hash is soft-deleted, and if so, soft-delete this publish
        if ($this->hash->deleted_at !== null) {
            $this->update(['status' => PublishStatusEnum::SOFT_DELETED]);

            return false;
        }

        // Increment attempts and mark as dispatched
        $this->update([
            'status' => PublishStatusEnum::DISPATCHED,
            'attempts' => $this->attempts + 1,
        ]);

        try {
            $publisherClass = $this->publisher->publisher_class;

            if (! class_exists($publisherClass)) {
                throw new \Exception("Publisher class {$publisherClass} not found");
            }

            $publisher = app($publisherClass);

            // Load hashable relation if not already loaded
            if (! $this->relationLoaded('hash') || ! $this->hash->relationLoaded('hashable')) {
                $this->load('hash.hashable');
            }

            $hashableModel = $this->hash->hashable;

            /** @phpstan-ignore-next-line */
            if (! $hashableModel) {
                throw new \Exception('Hashable model not found');
            }

            if (! $publisher->shouldPublish($hashableModel)) {
                $this->markAsPublished();

                return true;
            }

            $data = $publisher->getData($hashableModel);
            $success = $publisher->publish($hashableModel, $data);

            if ($success) {
                $this->markAsPublished();

                return true;
            } else {
                $this->markAsDeferred('Publisher returned false', null);

                return false;
            }

        } catch (\Exception $e) {
            $this->markAsDeferred($e->getMessage(), null);

            return false;
        }
    }

    /**
     * Publish immediately if no bulk job is running, otherwise let bulk job handle it
     */
    public function publishImmediatelyOrQueue(): bool
    {
        // Check if bulk job is running
        if (\Illuminate\Support\Facades\Cache::has('bulk_publish_job_running')) {
            // Bulk job is running, let it handle this record
            return true;
        }

        // No bulk job running, publish immediately
        return $this->publishNow();
    }
}
