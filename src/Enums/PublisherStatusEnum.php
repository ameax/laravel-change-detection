<?php

declare(strict_types=1);

namespace Ameax\LaravelChangeDetection\Enums;

enum PublisherStatusEnum: string
{
    case ACTIVE = 'active';
    case INACTIVE = 'inactive';

    public function isActive(): bool
    {
        return $this === self::ACTIVE;
    }

    public function isInactive(): bool
    {
        return $this === self::INACTIVE;
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
