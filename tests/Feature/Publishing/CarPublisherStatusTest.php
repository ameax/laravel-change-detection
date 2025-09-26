<?php

use Ameax\LaravelChangeDetection\Models\Hash;
use Ameax\LaravelChangeDetection\Models\Publish;
use Ameax\LaravelChangeDetection\Tests\Models\TestCar;
use Illuminate\Database\Eloquent\Relations\Relation;

beforeEach(function () {
    // Register TestCar in the morph map
    Relation::morphMap([
        'test_car' => TestCar::class,
    ]);

    // Load helper functions
    require_once __DIR__.'/../Helpers/CarHelpers.php';
});

describe('car publisher status', function () {

    it('handles multiple publishers for the same car independently', function () {
        // Create a car
        $car = createCar(['model' => 'Porsche 911', 'price' => 120000]);

        // Create multiple publishers for cars (different endpoints/platforms)
        $apiPublisher = createCarPublisher([
            'name' => 'Main API Publisher',
            'config' => ['endpoint' => 'https://api.main.com/cars'],
        ]);

        $backupPublisher = createCarPublisher([
            'name' => 'Backup API Publisher',
            'config' => ['endpoint' => 'https://api.backup.com/cars'],
        ]);

        $analyticsPublisher = createCarPublisher([
            'name' => 'Analytics Publisher',
            'config' => ['endpoint' => 'https://analytics.example.com/cars'],
        ]);

        // Initial sync to create hash and publish records for all publishers
        syncCars();

        $car->refresh();
        $hash = $car->getCurrentHash();

        // Verify 3 publish records were created (one per publisher)
        expect(Publish::count())->toBe(3);

        // Get all publish records
        $apiPublish = Publish::where('publisher_id', $apiPublisher->id)->first();
        $backupPublish = Publish::where('publisher_id', $backupPublisher->id)->first();
        $analyticsPublish = Publish::where('publisher_id', $analyticsPublisher->id)->first();

        // All should be pending initially
        expect($apiPublish->status)->toBe('pending');
        expect($backupPublish->status)->toBe('pending');
        expect($analyticsPublish->status)->toBe('pending');

        // All should point to the same hash
        expect($apiPublish->hash_id)->toBe($hash->id);
        expect($backupPublish->hash_id)->toBe($hash->id);
        expect($analyticsPublish->hash_id)->toBe($hash->id);

        // Simulate different publish states
        // API publisher succeeds
        $apiPublish->update([
            'status' => 'published',
            'published_at' => now(),
            'published_hash' => $hash->composite_hash, // Set the published hash
            'attempts' => 1,
        ]);

        // Backup publisher fails
        $backupPublish->update([
            'status' => 'failed',
            'attempts' => 3,
            'last_error' => 'Connection timeout',
        ]);

        // Analytics publisher remains pending

        // Update the car
        $originalCompositeHash = $hash->composite_hash;
        $car->update(['price' => 125000]);

        // Run sync again
        syncCars();

        // Verify hash was updated
        $car->refresh();
        $updatedHash = $car->getCurrentHash();
        expect($updatedHash->id)->toBe($hash->id); // Same record
        expect($updatedHash->composite_hash)->not->toBe($originalCompositeHash);

        // Check publish records after sync
        $apiPublish->refresh();
        $backupPublish->refresh();
        $analyticsPublish->refresh();

        // Published record should reset to pending with attempts reset
        expect($apiPublish->status)->toBe('pending');
        expect($apiPublish->attempts)->toBe(0);
        expect($apiPublish->hash_id)->toBe($hash->id); // Still same hash record

        // Failed record should reset to pending with attempts reset
        expect($backupPublish->status)->toBe('pending');
        expect($backupPublish->attempts)->toBe(0);
        expect($backupPublish->last_error)->toBeNull();

        // Pending record should remain pending
        expect($analyticsPublish->status)->toBe('pending');
        expect($analyticsPublish->attempts)->toBe(0);

        // Still only 3 publish records (no duplicates created)
        expect(Publish::count())->toBe(3);
    });

    it('respects publisher activation status during sync', function () {
        // Create two cars
        $car1 = createCar(['model' => 'Tesla Model X', 'price' => 90000]);
        $car2 = createCar(['model' => 'Tesla Model Y', 'price' => 50000]);

        // Create an active publisher
        $publisher = createCarPublisher([
            'name' => 'Primary Publisher',
            'status' => 'active',
        ]);

        // Initial sync - publisher is active
        syncCars();

        // Verify publish records were created for both cars
        expect(Publish::count())->toBe(2);

        $car1->refresh();
        $car2->refresh();
        $hash1 = $car1->getCurrentHash();
        $hash2 = $car2->getCurrentHash();

        $publish1 = Publish::where('hash_id', $hash1->id)->first();
        $publish2 = Publish::where('hash_id', $hash2->id)->first();

        expect($publish1)->not->toBeNull();
        expect($publish2)->not->toBeNull();
        expect($publish1->status)->toBe('pending');
        expect($publish2->status)->toBe('pending');

        // Deactivate the publisher
        $publisher->update(['status' => 'inactive']);

        // Update both cars
        $car1->update(['price' => 95000]);
        $car2->update(['price' => 55000]);

        // Run sync with deactivated publisher
        syncCars();

        // Verify no new publish records were created
        expect(Publish::count())->toBe(2); // Still only 2 records

        // Verify existing publish records were NOT modified (still pending with original hash)
        $publish1->refresh();
        $publish2->refresh();
        expect($publish1->status)->toBe('pending');
        expect($publish2->status)->toBe('pending');
        expect($publish1->hash_id)->toBe($hash1->id);
        expect($publish2->hash_id)->toBe($hash2->id);

        // Create a third car while publisher is inactive
        $car3 = createCar(['model' => 'Tesla Roadster', 'price' => 200000]);

        // Run sync - should not create publish for new car
        syncCars();
        expect(Publish::count())->toBe(2); // Still only 2 records

        // Reactivate the publisher
        $publisher->update(['status' => 'active']);

        // Run sync with reactivated publisher
        syncCars();

        // Now we should have 3 publish records (added one for car3)
        expect(Publish::count())->toBe(3);

        // Verify car3 now has a publish record
        $car3->refresh();
        $hash3 = $car3->getCurrentHash();
        $publish3 = Publish::where('hash_id', $hash3->id)->first();
        expect($publish3)->not->toBeNull();
        expect($publish3->status)->toBe('pending');

        // Verify car1 and car2 publish records were reset due to hash changes
        $publish1->refresh();
        $publish2->refresh();

        // Since the hashes changed while publisher was inactive,
        // they should now be reset to pending with updated content
        expect($publish1->status)->toBe('pending');
        expect($publish2->status)->toBe('pending');

        // Update a car again with publisher active
        $car1->update(['price' => 100000]);

        // Mark publish1 as published first
        $publish1->update([
            'status' => 'published',
            'published_at' => now(),
            'published_hash' => $car1->getCurrentHash()->composite_hash,
        ]);

        // Now update the car
        $car1->update(['price' => 105000]);

        // Run sync
        syncCars();

        // Verify the published record was properly reset
        $publish1->refresh();
        expect($publish1->status)->toBe('pending');
        expect($publish1->attempts)->toBe(0);
    });

    it('handles multiple publishers racing for the same model', function () {
        $car = createCar(['model' => 'Pagani Huayra', 'price' => 2500000]);

        // Create multiple publishers
        $publisher1 = createCarPublisher(['name' => 'API Publisher 1']);
        $publisher2 = createCarPublisher(['name' => 'API Publisher 2']);
        $publisher3 = createCarPublisher(['name' => 'API Publisher 3']);

        // Run sync - should create publish records for all publishers
        syncCars();

        // Verify 3 publish records created
        expect(Publish::count())->toBe(3);

        // Simulate concurrent processing of different publishers
        $publishes = Publish::all();

        // Each can be processed independently
        foreach ($publishes as $index => $publish) {
            if ($index === 0) {
                $publish->update(['status' => 'dispatched']);
            } elseif ($index === 1) {
                $publish->update(['status' => 'published', 'published_at' => now()]);
            }
            // Third remains pending
        }

        // Run sync again
        syncCars();

        // Verify each maintains its own state
        $publishes = Publish::orderBy('id')->get();
        expect($publishes[0]->status)->toBe('dispatched');
        expect($publishes[1]->status)->toBe('published');
        expect($publishes[2]->status)->toBe('pending');

        // No duplicates created
        expect(Publish::count())->toBe(3);
    });

});
