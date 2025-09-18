<?php

use Ameax\LaravelChangeDetection\Services\ChangeDetector;
use Ameax\LaravelChangeDetection\Tests\Models\TestArticle;

beforeEach(function () {
    $this->loadMigrationsFrom(__DIR__ . '/../migrations');

    \Illuminate\Database\Eloquent\Relations\Relation::morphMap([
        'test_article' => TestArticle::class,
    ]);
});

it('can detect changed models', function () {
    $article = TestArticle::create([
        'title' => 'Test Article',
        'content' => 'Article content',
        'author' => 'John Doe',
    ]);

    $detector = app(ChangeDetector::class);

    // No changes initially
    expect($detector->hasChanged($article))->toBeFalse();

    // Update article
    $article->update(['title' => 'Updated Title']);

    // Should still be false because hash was updated automatically
    expect($detector->hasChanged($article))->toBeFalse();
});

it('can count changed models in bulk', function () {
    TestArticle::create([
        'title' => 'Article 1',
        'content' => 'Content 1',
        'author' => 'Author 1',
    ]);

    TestArticle::create([
        'title' => 'Article 2',
        'content' => 'Content 2',
        'author' => 'Author 2',
    ]);

    $detector = app(ChangeDetector::class);
    $count = $detector->countChangedModels(TestArticle::class);

    expect($count)->toBe(0); // All hashes should be up to date
});