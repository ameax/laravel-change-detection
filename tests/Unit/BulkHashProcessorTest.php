<?php

use Ameax\LaravelChangeDetection\Services\BulkHashProcessor;
use Ameax\LaravelChangeDetection\Tests\Models\TestArticle;
use Ameax\LaravelChangeDetection\Models\Hash;

beforeEach(function () {
    $this->loadMigrationsFrom(__DIR__ . '/../migrations');

    \Illuminate\Database\Eloquent\Relations\Relation::morphMap([
        'test_article' => TestArticle::class,
    ]);
});

it('can process changed models in bulk', function () {
    // Create multiple articles
    $articles = collect();
    for ($i = 1; $i <= 5; $i++) {
        $articles->push(TestArticle::create([
            'title' => "Article {$i}",
            'content' => "Content for article {$i}",
            'author' => "Author {$i}",
        ]));
    }

    // Manually modify them without updating hashes
    foreach ($articles as $article) {
        TestArticle::withoutEvents(function () use ($article) {
            $article->update(['title' => 'Bulk Updated: ' . $article->title]);
        });
    }

    $processor = app(BulkHashProcessor::class);
    $updated = $processor->processChangedModels(TestArticle::class);

    expect($updated)->toBe(5);

    // Verify all hashes are now up to date
    foreach ($articles as $article) {
        expect($article->fresh()->hasHashChanged())->toBeFalse();
    }
});

it('can update hashes for specific model IDs', function () {
    $articles = collect();
    for ($i = 1; $i <= 3; $i++) {
        $articles->push(TestArticle::create([
            'title' => "Article {$i}",
            'content' => "Content for article {$i}",
            'author' => "Author {$i}",
        ]));
    }

    // Get two specific IDs
    $targetIds = $articles->take(2)->pluck('id')->toArray();

    // Manually modify all articles without updating hashes
    foreach ($articles as $article) {
        TestArticle::withoutEvents(function () use ($article) {
            $article->update(['title' => 'Modified: ' . $article->title]);
        });
    }

    $processor = app(BulkHashProcessor::class);
    $updated = $processor->updateHashesForIds(TestArticle::class, $targetIds);

    expect($updated)->toBe(2);

    // Verify only the targeted articles have updated hashes
    $targetArticles = TestArticle::whereIn('id', $targetIds)->get();
    foreach ($targetArticles as $article) {
        expect($article->hasHashChanged())->toBeFalse();
    }

    // The third article should still have outdated hash
    $untargetedArticle = $articles->last()->fresh();
    expect($untargetedArticle->hasHashChanged())->toBeTrue();
});

it('handles empty model ID arrays gracefully', function () {
    $processor = app(BulkHashProcessor::class);
    $updated = $processor->updateHashesForIds(TestArticle::class, []);

    expect($updated)->toBe(0);
});

it('respects batch size configuration', function () {
    $processor = app(BulkHashProcessor::class);

    expect($processor->getBatchSize())->toBe(1000); // Default

    $processor->setBatchSize(500);
    expect($processor->getBatchSize())->toBe(500);

    $processor->setBatchSize(0); // Should set to 1 minimum
    expect($processor->getBatchSize())->toBe(1);
});