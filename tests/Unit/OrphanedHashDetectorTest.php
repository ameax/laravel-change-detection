<?php

use Ameax\LaravelChangeDetection\Services\OrphanedHashDetector;
use Ameax\LaravelChangeDetection\Tests\Models\TestArticle;
use Ameax\LaravelChangeDetection\Models\Hash;

beforeEach(function () {
    $this->loadMigrationsFrom(__DIR__ . '/../migrations');

    \Illuminate\Database\Eloquent\Relations\Relation::morphMap([
        'test_article' => TestArticle::class,
    ]);
});

it('can detect orphaned hashes for deleted models', function () {
    // Create articles and their hashes
    $article1 = TestArticle::create([
        'title' => 'Article 1',
        'content' => 'Content 1',
        'author' => 'Author 1',
    ]);

    $article2 = TestArticle::create([
        'title' => 'Article 2',
        'content' => 'Content 2',
        'author' => 'Author 2',
    ]);

    // Verify hashes exist
    expect(Hash::where('hashable_type', 'test_article')->count())->toBe(2);

    // Delete one model directly (hard delete)
    TestArticle::withoutEvents(function () use ($article1) {
        $article1->forceDelete();
    });

    $detector = app(OrphanedHashDetector::class);
    $orphaned = $detector->detectOrphanedHashes(TestArticle::class);

    expect($orphaned)->toHaveCount(1)
        ->and($orphaned[0]['model_id'])->toBe($article1->id);
});

it('can count orphaned hashes', function () {
    // Create articles
    $articles = collect();
    for ($i = 1; $i <= 3; $i++) {
        $articles->push(TestArticle::create([
            'title' => "Article {$i}",
            'content' => "Content {$i}",
            'author' => "Author {$i}",
        ]));
    }

    // Hard delete two articles
    TestArticle::withoutEvents(function () use ($articles) {
        $articles->take(2)->each->forceDelete();
    });

    $detector = app(OrphanedHashDetector::class);
    $count = $detector->countOrphanedHashes(TestArticle::class);

    expect($count)->toBe(2);
});

it('can cleanup orphaned hashes', function () {
    $article = TestArticle::create([
        'title' => 'Test Article',
        'content' => 'Test Content',
        'author' => 'Test Author',
    ]);

    // Verify hash exists
    expect(Hash::where('hashable_type', 'test_article')->count())->toBe(1);

    // Hard delete the model
    TestArticle::withoutEvents(function () use ($article) {
        $article->forceDelete();
    });

    $detector = app(OrphanedHashDetector::class);
    $cleaned = $detector->cleanupOrphanedHashes(TestArticle::class);

    expect($cleaned)->toBe(1);

    // Verify hash is marked as deleted
    $hash = Hash::where('hashable_type', 'test_article')
               ->where('hashable_id', $article->id)
               ->first();

    expect($hash->deleted_at)->not()->toBeNull();
});

it('can mark specific hash IDs as deleted', function () {
    $article = TestArticle::create([
        'title' => 'Test Article',
        'content' => 'Test Content',
        'author' => 'Test Author',
    ]);

    $hash = $article->getCurrentHash();

    $detector = app(OrphanedHashDetector::class);
    $marked = $detector->markHashesAsDeleted([$hash->id]);

    expect($marked)->toBe(1);

    // Verify hash is marked as deleted
    $hash->refresh();
    expect($hash->deleted_at)->not()->toBeNull();
});

it('handles empty hash ID arrays gracefully', function () {
    $detector = app(OrphanedHashDetector::class);
    $marked = $detector->markHashesAsDeleted([]);

    expect($marked)->toBe(0);
});