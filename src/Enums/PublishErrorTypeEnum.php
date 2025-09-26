<?php

declare(strict_types=1);

namespace Ameax\LaravelChangeDetection\Enums;

enum PublishErrorTypeEnum: string
{
    case VALIDATION = 'validation';
    case INFRASTRUCTURE = 'infrastructure';
    case DATA = 'data';
    case UNKNOWN = 'unknown';

    public function isValidation(): bool
    {
        return $this === self::VALIDATION;
    }

    public function isInfrastructure(): bool
    {
        return $this === self::INFRASTRUCTURE;
    }

    public function isData(): bool
    {
        return $this === self::DATA;
    }

    public function isUnknown(): bool
    {
        return $this === self::UNKNOWN;
    }

    /**
     * Determine if the error is retryable based on type
     */
    public function isRetryable(): bool
    {
        return match ($this) {
            self::INFRASTRUCTURE, self::UNKNOWN => true,
            self::VALIDATION, self::DATA => false,
        };
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
