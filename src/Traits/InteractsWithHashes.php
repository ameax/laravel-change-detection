<?php

declare(strict_types=1);

namespace Ameax\LaravelChangeDetection\Traits;

use Ameax\LaravelChangeDetection\Models\Hash;
use Illuminate\Database\Eloquent\Relations\MorphOne;

trait InteractsWithHashes
{
    public function hash(): MorphOne
    {
        return $this->morphOne(Hash::class, 'hashable');
    }

    public function getCurrentHash(): ?Hash
    {
        return $this->hash()->active()->first();
    }

    public function getHashableScope(): ?\Closure
    {
        return null;
    }
}