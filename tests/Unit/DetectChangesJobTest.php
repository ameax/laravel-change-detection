<?php

use Ameax\LaravelChangeDetection\Jobs\DetectChangesJob;
use Ameax\LaravelChangeDetection\Tests\Models\TestArticle;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $this->loadMigrationsFrom(__DIR__ . '/../migrations');

    \Illuminate\Database\Eloquent\Relations\Relation::morphMap([
        'test_article' => TestArticle::class,
    ]);
});

it('can create a detect changes job', function () {
    $job = new DetectChangesJob(
        TestArticle::class,
        updateHashes: true,
        cleanupOrphaned: false,
        limit: 500
    );

    expect($job->getModelClass())->toBe(TestArticle::class)
        ->and($job->shouldUpdateHashes())->toBeTrue()
        ->and($job->shouldCleanupOrphaned())->toBeFalse()
        ->and($job->getLimit())->toBe(500);
});

it('can dispatch a detect changes job', function () {
    Queue::fake();

    DetectChangesJob::dispatch(TestArticle::class, true, false, 100);

    Queue::assertPushed(DetectChangesJob::class, function ($job) {
        return $job->getModelClass() === TestArticle::class &&
               $job->shouldUpdateHashes() === true &&
               $job->shouldCleanupOrphaned() === false &&
               $job->getLimit() === 100;
    });
});

it('uses correct queue from config', function () {
    config(['change-detection.queues.detect_changes' => 'custom-queue']);

    $job = new DetectChangesJob(TestArticle::class);

    expect($job->queue)->toBe('custom-queue');
});

it('has reasonable timeout and retry settings', function () {
    $job = new DetectChangesJob(TestArticle::class);

    expect($job->timeout)->toBe(300) // 5 minutes
        ->and($job->tries)->toBe(3);
});