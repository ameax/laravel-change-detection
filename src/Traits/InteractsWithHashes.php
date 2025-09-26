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

    public function getHashRelationsToNotifyOnChange(): array
    {
        return [];
    }

    public function getHashDependents(): \Illuminate\Database\Eloquent\Collection
    {
        $currentHash = $this->getCurrentHash();
        if (! $currentHash) {
            return collect();
        }

        return $currentHash->dependents;
    }

    public function getHashPublishes(): \Illuminate\Database\Eloquent\Collection
    {
        $currentHash = $this->getCurrentHash();
        if (! $currentHash) {
            return collect();
        }

        return $currentHash->publishes;
    }

    public function getHashLastUpdated(): ?\Illuminate\Support\Carbon
    {
        return $this->getCurrentHash()?->updated_at;
    }

    public function isHashDeleted(): bool
    {
        return $this->getCurrentHash()?->isDeleted() ?? false;
    }

    /**
     * Reset all publish errors for this model's hash.
     * Sets failed/deferred publishes back to pending status.
     */
    public function resetPublishErrors(): int
    {
        $currentHash = $this->getCurrentHash();
        if (! $currentHash) {
            return 0;
        }

        $updatedCount = $currentHash->publishes()
            ->whereIn('status', ['failed', 'deferred'])
            ->update([
                'status' => 'pending',
                'attempts' => 0,
                'last_error' => null,
                'last_response_code' => null,
                'error_type' => null,
                'next_try' => null,
            ]);

        return $updatedCount;
    }

    /**
     * Reset publish errors for a specific publisher.
     */
    public function resetPublishErrorsForPublisher(int $publisherId): int
    {
        $currentHash = $this->getCurrentHash();
        if (! $currentHash) {
            return 0;
        }

        $updatedCount = $currentHash->publishes()
            ->where('publisher_id', $publisherId)
            ->whereIn('status', ['failed', 'deferred'])
            ->update([
                'status' => 'pending',
                'attempts' => 0,
                'last_error' => null,
                'last_response_code' => null,
                'error_type' => null,
                'next_try' => null,
            ]);

        return $updatedCount;
    }

    /**
     * Get count of failed/deferred publishes for this model.
     */
    public function getPublishErrorCount(): int
    {
        $currentHash = $this->getCurrentHash();
        if (! $currentHash) {
            return 0;
        }

        return $currentHash->publishes()
            ->whereIn('status', ['failed', 'deferred'])
            ->count();
    }

    /**
     * Get detailed publish status information.
     */
    public function getPublishStatus(): array
    {
        $currentHash = $this->getCurrentHash();
        if (! $currentHash) {
            return [
                'pending' => 0,
                'published' => 0,
                'failed' => 0,
                'deferred' => 0,
                'total' => 0,
            ];
        }

        $statuses = $currentHash->publishes()
            ->selectRaw('status, COUNT(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status')
            ->toArray();

        return [
            'pending' => $statuses['pending'] ?? 0,
            'published' => $statuses['published'] ?? 0,
            'failed' => $statuses['failed'] ?? 0,
            'deferred' => $statuses['deferred'] ?? 0,
            'total' => array_sum($statuses),
        ];
    }
}
