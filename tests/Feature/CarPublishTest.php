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
            'published_hash' => $originalCompositeHash, // Set the published hash
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
            'published_hash' => $hash->composite_hash, // Set the published hash
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

    it('respects publisher activation status during sync', function () {
        // Create two cars
        $car1 = createCar(['model' => 'Tesla Model X', 'price' => 90000]);
        $car2 = createCar(['model' => 'Tesla Model Y', 'price' => 50000]);

        // Create an active publisher
        $publisher = createCarPublisher([
            'name' => 'Primary Publisher',
            'status' => 'active'
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
            'published_hash' => $car1->getCurrentHash()->composite_hash
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

    it('handles soft-deleted models correctly', function () {
        // Create a car and publisher
        $car = createCar(['model' => 'Audi RS6', 'price' => 110000]);
        $publisher = createCarPublisher();

        // Initial sync to create hash and publish record
        syncCars();

        $car->refresh();
        $hash = $car->getCurrentHash();
        $publish = assertPublishExists($car, $publisher, 'pending');

        // Verify initial state
        expect($hash)->not->toBeNull();
        expect($hash->deleted_at)->toBeNull();
        expect($publish->status)->toBe('pending');

        // Soft delete the car
        $car->delete();
        expect($car->trashed())->toBeTrue();

        // Run sync to process the soft deletion
        syncCars();

        // Verify hash was soft-deleted
        $hash->refresh();
        expect($hash->deleted_at)->not->toBeNull();

        // Verify publish record still exists but should not be processed
        $publish->refresh();
        expect($publish->exists)->toBeTrue();

        // Create another car while first is deleted
        $car2 = createCar(['model' => 'Audi Q8', 'price' => 85000]);

        // Run sync - should only create records for car2
        syncCars();

        $car2->refresh();
        $hash2 = $car2->getCurrentHash();
        expect($hash2)->not->toBeNull();
        expect(Publish::count())->toBe(2); // One for each car

        // Restore the first car
        $car->restore();
        expect($car->trashed())->toBeFalse();

        // Run sync to process the restoration
        syncCars();

        // Verify hash was restored
        $hash->refresh();
        expect($hash->deleted_at)->toBeNull();

        // Verify publish record is still pending and can be processed
        $publish->refresh();
        expect($publish->status)->toBe('pending');

        // Update the restored car
        $originalCompositeHash = $hash->composite_hash;
        $car->update(['price' => 115000]);

        // Run sync
        syncCars();

        // Verify hash was updated
        $car->refresh();
        $currentHash = $car->getCurrentHash();
        expect($currentHash->id)->toBe($hash->id); // Same record
        expect($currentHash->composite_hash)->not->toBe($originalCompositeHash);

        // Verify publish record is still pending (ready to publish the updated content)
        $publish->refresh();
        expect($publish->status)->toBe('pending');
        expect($publish->hash_id)->toBe($hash->id);
    });

    it('does not create publish records for soft-deleted models', function () {
        // Create a publisher
        $publisher = createCarPublisher();

        // Create a car and immediately soft-delete it
        $car = createCar(['model' => 'BMW M5', 'price' => 105000]);
        $car->delete();

        // Run sync
        syncCars();

        // Verify hash was created but marked as deleted
        $hash = Hash::where('hashable_type', 'test_car')
            ->where('hashable_id', $car->id)
            ->first();

        expect($hash)->not->toBeNull();
        expect($hash->deleted_at)->not->toBeNull();

        // Verify NO publish record was created for the soft-deleted model
        $publishCount = Publish::whereHas('hash', function ($query) use ($car) {
            $query->where('hashable_type', 'test_car')
                  ->where('hashable_id', $car->id);
        })->count();

        expect($publishCount)->toBe(0);
    });

});