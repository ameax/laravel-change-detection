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

describe('car soft-delete publishing', function () {

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
        expect($publish->status)->toBe(PublishStatusEnum::PENDING);

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
        expect($publish->status)->toBe(PublishStatusEnum::PENDING);

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
        expect($publish->status)->toBe(PublishStatusEnum::PENDING);
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
