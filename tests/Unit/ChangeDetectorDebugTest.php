<?php

use Ameax\LaravelChangeDetection\Models\Hash;
use Ameax\LaravelChangeDetection\Services\ChangeDetector;
use Ameax\LaravelChangeDetection\Tests\Models\TestArticle;

beforeEach(function () {
    $this->loadMigrationsFrom(__DIR__.'/../migrations');

    \Illuminate\Database\Eloquent\Relations\Relation::morphMap([
        'test_article' => TestArticle::class,
    ]);
});

it('debugs why change detector finds changes', function () {
    $article = TestArticle::create([
        'title' => 'Test Article',
        'content' => 'Article content',
        'author' => 'John Doe',
    ]);

    // Check what hash was created
    $hash = $article->getCurrentHash();
    dump('Hash record:', [
        'id' => $hash->id,
        'hashable_type' => $hash->hashable_type,
        'hashable_id' => $hash->hashable_id,
        'attribute_hash' => $hash->attribute_hash,
        'composite_hash' => $hash->composite_hash,
        'deleted_at' => $hash->deleted_at,
    ]);

    $detector = app(ChangeDetector::class);

    // Check if detector thinks it changed
    $hasChanged = $detector->hasChanged($article);
    dump('Has changed:', $hasChanged);

    if ($hasChanged) {
        // Let's see what the detector calculates vs what's stored
        $compositeCalculator = app(\Ameax\LaravelChangeDetection\Services\CompositeHashCalculator::class);
        $calculatedHash = $compositeCalculator->calculate($article);

        dump('Calculated hash:', $calculatedHash);
        dump('Stored hash:', $hash->composite_hash);
        dump('Hashes match:', $calculatedHash === $hash->composite_hash);
    }

    // Get changed model IDs
    $changedIds = $detector->detectChangedModelIds(TestArticle::class);
    dump('Changed model IDs:', $changedIds);

    expect(true)->toBeTrue(); // Just to make test pass while debugging
});
