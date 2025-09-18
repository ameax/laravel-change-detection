<?php

use Ameax\LaravelChangeDetection\Tests\Models\TestArticle;

beforeEach(function () {
    $this->loadMigrationsFrom(__DIR__ . '/../migrations');

    \Illuminate\Database\Eloquent\Relations\Relation::morphMap([
        'test_article' => TestArticle::class,
    ]);
});

it('automatically creates hash when model is saved', function () {
    $article = TestArticle::create([
        'title' => 'Test Article',
        'content' => 'Article content',
        'author' => 'John Doe',
    ]);

    $hash = $article->getCurrentHash();

    expect($hash)->not()->toBeNull()
        ->and($hash->hashable_type)->toBe('test_article')
        ->and($hash->hashable_id)->toBe($article->id)
        ->and($hash->attribute_hash)->toBeString()
        ->and($hash->composite_hash)->toBeString();
});

it('can check if hash has changed after save', function () {
    $article = TestArticle::create([
        'title' => 'Test Article',
        'content' => 'Article content',
        'author' => 'John Doe',
    ]);

    expect($article->hasHashChanged())->toBeFalse();

    $article->update(['title' => 'Updated Title']);
    expect($article->hasHashChanged())->toBeFalse(); // Should be false because hash was auto-updated

    // Manually change attribute without saving to test detection
    $article->title = 'Manually Changed Title';
    expect($article->hasHashChanged())->toBeTrue();
});