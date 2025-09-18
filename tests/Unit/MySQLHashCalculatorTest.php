<?php

use Ameax\LaravelChangeDetection\Services\MySQLHashCalculator;
use Ameax\LaravelChangeDetection\Tests\Models\TestArticle;

beforeEach(function () {
    // Load test migrations
    $this->loadMigrationsFrom(__DIR__ . '/../migrations');

    // Set up morph map
    \Illuminate\Database\Eloquent\Relations\Relation::morphMap([
        'test_article' => TestArticle::class,
    ]);
});

it('can calculate attribute hash for a single model', function () {
    $article = TestArticle::create([
        'title' => 'Test Article',
        'content' => 'Article content',
        'author' => 'John Doe',
    ]);

    $calculator = app(MySQLHashCalculator::class);
    $hash = $calculator->calculateAttributeHash($article);

    expect($hash)->toBeString()
        ->and(strlen($hash))->toBe(32); // MD5 hash length
});