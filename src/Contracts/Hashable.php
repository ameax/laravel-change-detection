<?php

declare(strict_types=1);

namespace Ameax\LaravelChangeDetection\Contracts;

interface Hashable
{
    /**
     * Get the attributes that should be included in the hash.
     *
     * @return array<string>
     */
    public function getHashableAttributes(): array;

    /**
     * Get the relations that should be included in the composite hash.
     * Can include nested relations using dot notation (e.g., 'posts.comments').
     *
     * @return array<string>
     */
    public function getHashCompositeDependencies(): array;

    /**
     * Get the parent relations that should be notified when this model changes.
     * Returns an array of relation names that point to parent models whose
     * composite hashes should be recalculated when this model is modified.
     *
     * Example:
     * return ['weatherStation', 'maintenanceCompany'];
     *
     * @return array<string>
     */
    public function getHashParentRelations(): array;

    /**
     * Get the current hash record for this model.
     *
     * @return \Ameax\LaravelChangeDetection\Models\Hash|null
     */
    public function getCurrentHash(): ?object;

    /**
     * Get the query scope for filtering hashable records.
     * Return a closure that accepts a query builder and applies filtering,
     * or null if no filtering should be applied.
     *
     * Example:
     * return function ($query) {
     *     return $query->whereNotNull('kundennr');
     * };
     */
    public function getHashableScope(): ?\Closure;
}
