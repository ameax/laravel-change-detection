<?php

declare(strict_types=1);

namespace Ameax\LaravelChangeDetection\Enums;

enum PublishStatusEnum: string
{
    case PENDING = 'pending';
    case DISPATCHED = 'dispatched';
    case DEFERRED = 'deferred';
    case PUBLISHED = 'published';
    case FAILED = 'failed';
    case SOFT_DELETED = 'soft-deleted';

    public function isPending(): bool
    {
        return $this === self::PENDING;
    }

    public function isDispatched(): bool
    {
        return $this === self::DISPATCHED;
    }

    public function isDeferred(): bool
    {
        return $this === self::DEFERRED;
    }

    public function isPublished(): bool
    {
        return $this === self::PUBLISHED;
    }

    public function isFailed(): bool
    {
        return $this === self::FAILED;
    }

    public function isSoftDeleted(): bool
    {
        return $this === self::SOFT_DELETED;
    }

    public function shouldRetry(): bool
    {
        return $this === self::DEFERRED;
    }

    public function canProcess(): bool
    {
        return in_array($this, [self::PENDING, self::DEFERRED], true);
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::PUBLISHED, self::FAILED, self::SOFT_DELETED], true);
    }

    /**
     * Get all values as array for database enum
     *
     * @return array<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}