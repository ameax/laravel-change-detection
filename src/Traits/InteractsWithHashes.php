<?php

declare(strict_types=1);

namespace Ameax\LaravelChangeDetection\Traits;

use Ameax\LaravelChangeDetection\Models\Hash;
use Ameax\LaravelChangeDetection\Contracts\Hashable;
use Illuminate\Database\Eloquent\Relations\MorphOne;

trait InteractsWithHashes
{
    public static function bootInteractsWithHashes(): void
    {
        static::saved(function ($model) {
            if ($model instanceof Hashable) {
                $model->updateHash();
            }
        });

        static::deleted(function ($model) {
            if ($model instanceof Hashable) {
                $model->markHashAsDeleted();
            }
        });
    }

    public function hash(): MorphOne
    {
        return $this->morphOne(Hash::class, 'hashable');
    }

    public function getCurrentHash(): ?Hash
    {
        return $this->hash()->active()->first();
    }

    public function updateHash(): void
    {
        // Will be implemented by services in Phase 3
        // app(HashUpdateService::class)->updateHash($this);
    }

    public function markHashAsDeleted(): void
    {
        $this->hash()?->markAsDeleted();
    }

    public function getHashableScope(): ?\Closure
    {
        return null;
    }

    public function getHashRelationsToNotifyOnChange(): array
    {
        return [];
    }
}