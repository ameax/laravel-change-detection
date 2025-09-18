<?php

use Ameax\LaravelChangeDetection\Publishers\LogPublisher;
use Ameax\LaravelChangeDetection\Tests\Models\TestArticle;

beforeEach(function () {
    $this->loadMigrationsFrom(__DIR__.'/../migrations');

    \Illuminate\Database\Eloquent\Relations\Relation::morphMap([
        'test_article' => TestArticle::class,
    ]);
});

it('can publish hash changes to log', function () {
    $article = TestArticle::create([
        'title' => 'Test Article',
        'content' => 'Test Content',
        'author' => 'Test Author',
    ]);

    $logPublisher = new LogPublisher;
    $data = $logPublisher->getData($article);
    $result = $logPublisher->publish($article, $data);

    expect($result)->toBeTrue();
    expect($data['model_type'])->toBe('test_article');
});

it('can be configured with custom settings', function () {
    $publisher = new LogPublisher('custom-channel', 'debug', false);

    expect($publisher->getLogChannel())->toBe('custom-channel')
        ->and($publisher->getLogLevel())->toBe('debug')
        ->and($publisher->shouldIncludeHashData())->toBeFalse();
});

it('can update settings fluently', function () {
    $publisher = new LogPublisher;

    $updated = $publisher
        ->setLogChannel('test-channel')
        ->setLogLevel('warning')
        ->setIncludeHashData(false);

    expect($updated)->toBe($publisher)
        ->and($publisher->getLogChannel())->toBe('test-channel')
        ->and($publisher->getLogLevel())->toBe('warning')
        ->and($publisher->shouldIncludeHashData())->toBeFalse();
});

it('includes hash data when configured to do so', function () {
    $article = TestArticle::create([
        'title' => 'Test Article',
        'content' => 'Test Content',
        'author' => 'Test Author',
    ]);

    $logPublisher = new LogPublisher(includeHashData: true);
    $data = $logPublisher->getData($article);

    expect($data)->toHaveKey('hash_data')
        ->and($data)->toHaveKey('model_data')
        ->and($data['model_data'])->toHaveKey('title');
});

it('can get data from hashable models', function () {
    $article = TestArticle::create([
        'title' => 'Test Article',
        'content' => 'Test Content',
        'author' => 'Test Author',
    ]);

    $logPublisher = new LogPublisher;
    $data = $logPublisher->getData($article);

    expect($data)->toHaveKey('model_type')
        ->and($data)->toHaveKey('model_id')
        ->and($data)->toHaveKey('timestamp')
        ->and($data['model_type'])->toBe('test_article');
});

it('does not retry on failures', function () {
    $publisher = new LogPublisher;

    expect($publisher->shouldPublish(new TestArticle))->toBeTrue()
        ->and($publisher->getMaxAttempts())->toBe(1);
});
