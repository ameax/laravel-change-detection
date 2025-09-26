<?php

use Ameax\LaravelChangeDetection\Models\Publish;
use Ameax\LaravelChangeDetection\Tests\Models\TestCar;
use Illuminate\Database\Eloquent\Relations\Relation;

beforeEach(function () {
    // Register TestCar in the morph map
    Relation::morphMap([
        'test_car' => TestCar::class,
    ]);

    // Load helper functions
    require_once __DIR__ . '/Helpers/CarHelpers.php';
});

describe('car publishing', function () {

    it('creates publish records when car hash is synced with active publisher', function () {
        // Create a car
        $car = createCar([
            'model' => 'Tesla Model S',
            'price' => 80000
        ]);

        // Create an active publisher for cars
        $publisher = createCarPublisher();

        // Verify no hash or publish records exist yet
        expect($car->getCurrentHash())->toBeNull();
        expect(Publish::count())->toBe(0);

        // Run sync command
        syncCars();

        // Verify hash was created
        $car->refresh();
        $hash = assertCarHasHash($car);

        // Verify publish record was created with pending status
        $publish = assertPublishExists($car, $publisher, 'pending');

        // Verify publish record has correct data
        expect($publish->attempts)->toBe(0);
        expect($publish->last_error)->toBeNull();
        expect($publish->published_at)->toBeNull();
    });

    it('does not create publish records when no active publisher exists', function () {
        // Create a car
        $car = createCar([
            'model' => 'BMW M3',
            'price' => 70000,
            'is_electric' => false
        ]);

        // Create an inactive publisher (should be ignored)
        $inactivePublisher = createCarPublisher([
            'name' => 'Inactive Car Publisher',
            'status' => 'inactive'
        ]);

        // Verify initial state
        expect($car->getCurrentHash())->toBeNull();
        expect(Publish::count())->toBe(0);

        // Run sync command
        syncCars();

        // Verify hash was created
        $car->refresh();
        $hash = assertCarHasHash($car);

        // Verify NO publish record was created (publisher is inactive)
        expect(Publish::count())->toBe(0);

        // Verify we can't find a publish for this car/publisher combo
        $publish = Publish::where('hash_id', $hash->id)
            ->where('publisher_id', $inactivePublisher->id)
            ->first();

        expect($publish)->toBeNull();
    });

    it('does not create new publish when car changes but publish is still pending', function () {
        // Create a car and publisher
        $car = createCar(['model' => 'Mercedes C300', 'price' => 50000]);
        $publisher = createCarPublisher();

        // Initial sync to create hash and publish record
        syncCars();

        $car->refresh();
        $firstHash = $car->getCurrentHash();
        $originalCompositeHash = $firstHash->composite_hash;
        $publish = assertPublishExists($car, $publisher, 'pending');
        $originalPublishId = $publish->id;

        // Verify initial publish has the first hash
        expect($publish->hash_id)->toBe($firstHash->id);

        // Update the car
        $car->update(['price' => 55000]);

        // Run sync again
        syncCars();

        // Verify the hash was updated (composite_hash changed, but same record)
        $car->refresh();
        $newHash = $car->getCurrentHash();
        expect($newHash->id)->toBe($firstHash->id); // Same hash record
        expect($newHash->composite_hash)->not->toBe($originalCompositeHash); // But different hash value

        // Verify NO new publish record was created (still pending)
        expect(Publish::count())->toBe(1);

        // The existing publish should still be pending and unchanged
        $publish->refresh();
        expect($publish->id)->toBe($originalPublishId);
        expect($publish->hash_id)->toBe($firstHash->id); // Still points to original hash
        expect($publish->status)->toBe('pending');
    });

    it('resets publish to pending when car changes after successful publish', function () {
        // Create a car and publisher
        $car = createCar(['model' => 'BMW 530i', 'price' => 60000]);
        $publisher = createCarPublisher();

        // Initial sync
        syncCars();

        $car->refresh();
        $firstHash = $car->getCurrentHash();
        $originalCompositeHash = $firstHash->composite_hash;
        $publish = assertPublishExists($car, $publisher, 'pending');

        // Simulate successful publish - hash_id should stay with the published hash
        $publish->update([
            'status' => 'published',
            'published_at' => now(),
            'attempts' => 1,
            'hash_id' => $firstHash->id
        ]);

        // Update the car
        $car->update(['price' => 65000]);

        // Run sync again
        syncCars();

        // Verify the hash was updated (composite_hash changed, but same record)
        $car->refresh();
        $newHash = $car->getCurrentHash();
        expect($newHash->id)->toBe($firstHash->id); // Same hash record
        expect($newHash->composite_hash)->not->toBe($originalCompositeHash); // But different hash value

        // Verify the publish record was reset to pending
        $publish->refresh();
        expect($publish->hash_id)->toBe($firstHash->id); // Still points to the last published hash
        expect($publish->status)->toBe('pending');
        expect($publish->attempts)->toBe(0);

        // Still only ONE publish record per publisher
        expect(Publish::count())->toBe(1);
    });

    it('handles multiple publishers for the same car independently', function () {
        // Create a car
        $car = createCar(['model' => 'Porsche 911', 'price' => 120000]);

        // Create multiple publishers for cars (different endpoints/platforms)
        $apiPublisher = createCarPublisher([
            'name' => 'Main API Publisher',
            'config' => ['endpoint' => 'https://api.main.com/cars']
        ]);

        $backupPublisher = createCarPublisher([
            'name' => 'Backup API Publisher',
            'config' => ['endpoint' => 'https://api.backup.com/cars']
        ]);

        $analyticsPublisher = createCarPublisher([
            'name' => 'Analytics Publisher',
            'config' => ['endpoint' => 'https://analytics.example.com/cars']
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
            'attempts' => 1
        ]);

        // Backup publisher fails
        $backupPublish->update([
            'status' => 'failed',
            'attempts' => 3,
            'last_error' => 'Connection timeout'
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

});