<?php

use Ameax\LaravelChangeDetection\Services\CompositeHashCalculator;
use Ameax\LaravelChangeDetection\Tests\Models\TestArticle;

beforeEach(function () {
    $this->loadMigrationsFrom(__DIR__.'/../migrations');

    \Illuminate\Database\Eloquent\Relations\Relation::morphMap([
        'test_article' => TestArticle::class,
    ]);
});

it('can calculate composite hash for model without dependencies', function () {
    $article = TestArticle::create([
        'title' => 'Test Article',
        'content' => 'Article content',
        'author' => 'John Doe',
    ]);

    $calculator = app(CompositeHashCalculator::class);
    $hash = $calculator->calculate($article);

    expect($hash)->toBeString()
        ->and(strlen($hash))->toBe(32); // MD5 hash length
});
