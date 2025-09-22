<?php

declare(strict_types=1);

use Ameax\LaravelChangeDetection\Jobs\BulkPublishJob;
use Ameax\LaravelChangeDetection\Models\Hash;
use Ameax\LaravelChangeDetection\Models\Publish;
use Ameax\LaravelChangeDetection\Models\Publisher;
use Ameax\LaravelChangeDetection\Tests\TestPublisher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    Cache::flush();
});

it('processes pending publish records in batches', function () {
        // Create publisher
        $publisher = Publisher::create([
            'name' => 'Test Publisher',
            'model_type' => 'TestModel',
            'publisher_class' => TestPublisher::class,
            'status' => 'active',
        ]);

        // Create test model and hash
        $testModel = new class extends \Illuminate\Database\Eloquent\Model {
            protected $table = 'test_models';
            protected $fillable = ['name'];
        };

        $hash = Hash::create([
            'hashable_type' => get_class($testModel),
            'hashable_id' => 1,
            'attribute_hash' => 'test_hash',
            'composite_hash' => 'composite_hash',
        ]);

        // Create multiple publish records
        $publishRecords = collect();
        for ($i = 0; $i < 5; $i++) {
            $publishRecords->push(Publish::create([
                'hash_id' => $hash->id,
                'publisher_id' => $publisher->id,
                'status' => 'pending',
                'attempts' => 0,
            ]));
        }

        // Execute job
        $job = new BulkPublishJob();
        $job->handle();

        // Verify all records were processed
        expect(Publish::where('status', 'published')->count())->toBe(5);
        expect(Publish::where('status', 'pending')->count())->toBe(0);
    });

    it('ensures only one job runs at a time', function () {
        Queue::fake();

        // First job should acquire lock
        $job1 = new BulkPublishJob();
        $job2 = new BulkPublishJob();

        // Simulate first job running
        expect(Cache::lock('bulk_publish_job_running', 600)->get())->toBeTrue();

        // Second job should skip
        $job2->handle();

        // Lock should still be held
        expect(Cache::has('bulk_publish_job_running'))->toBeTrue();
    });

    it('applies 100ms delay between publishes', function () {
        $publisher = Publisher::create([
            'name' => 'Test Publisher',
            'model_type' => 'TestModel',
            'publisher_class' => TestPublisher::class,
            'status' => 'active',
        ]);

        $hash = Hash::create([
            'hashable_type' => 'TestModel',
            'hashable_id' => 1,
            'attribute_hash' => 'test_hash',
            'composite_hash' => 'composite_hash',
        ]);

        // Create 3 publish records
        for ($i = 0; $i < 3; $i++) {
            Publish::create([
                'hash_id' => $hash->id,
                'publisher_id' => $publisher->id,
                'status' => 'pending',
                'attempts' => 0,
            ]);
        }

        $startTime = microtime(true);

        $job = new BulkPublishJob();
        $job->handle();

        $endTime = microtime(true);
        $duration = ($endTime - $startTime) * 1000; // Convert to milliseconds

        // Should take at least 200ms (2 delays of 100ms between 3 records)
        expect($duration)->toBeGreaterThan(200);
    });

    it('dispatches next job when more records exist', function () {
        Queue::fake();

        $publisher = Publisher::create([
            'name' => 'Test Publisher',
            'model_type' => 'TestModel',
            'publisher_class' => TestPublisher::class,
            'status' => 'active',
        ]);

        $hash = Hash::create([
            'hashable_type' => 'TestModel',
            'hashable_id' => 1,
            'attribute_hash' => 'test_hash',
            'composite_hash' => 'composite_hash',
        ]);

        // Create more than batch size (2400) records
        for ($i = 0; $i < 2500; $i++) {
            Publish::create([
                'hash_id' => $hash->id,
                'publisher_id' => $publisher->id,
                'status' => 'pending',
                'attempts' => 0,
            ]);
        }

        $job = new BulkPublishJob();
        $job->handle();

        // Should process 2400 and dispatch next job for remaining 100
        expect(Publish::where('status', 'published')->count())->toBe(2400);
        expect(Publish::where('status', 'pending')->count())->toBe(100);

        Queue::assertPushed(BulkPublishJob::class, 1); // Next job dispatched
    });