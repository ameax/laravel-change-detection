<?php

use Ameax\LaravelChangeDetection\Enums\PublishStatusEnum;
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

describe('car basic publishing', function () {

    it('creates publish records when car hash is synced with active publisher', function () {
        // Create a car
        $car = createCar([
            'model' => 'Tesla Model S',
            'price' => 80000,
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
            'model' => 'Honda Civic',
            'price' => 25000,
        ]);

        // Verify no hash or publish records exist yet
        expect($car->getCurrentHash())->toBeNull();
        expect(Publish::count())->toBe(0);

        // Run sync command without any publisher
        syncCars();

        // Verify hash was created
        $car->refresh();
        $hash = assertCarHasHash($car);

        // Verify NO publish record was created
        expect(Publish::count())->toBe(0);

        // Create an inactive publisher
        $inactivePublisher = createCarPublisher(['status' => 'inactive']);

        // Run sync again
        syncCars();

        // Still no publish records for inactive publisher
        expect(Publish::count())->toBe(0);
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
        expect($publish->status)->toBe(PublishStatusEnum::PENDING);
    });

    it('resets publish to pending when car changes after successful publish', function () {
        // Create a car and publisher
        $car = createCar(['model' => 'BMW 530i', 'price' => 60000]);
        $publisher = createCarPublisher();

        // Initial sync to create hash and publish record
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
            'hash_id' => $firstHash->id,
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
        expect($publish->status)->toBe(PublishStatusEnum::PENDING);
        expect($publish->attempts)->toBe(0);

        // Still only ONE publish record per publisher
        expect(Publish::count())->toBe(1);
    });

});
