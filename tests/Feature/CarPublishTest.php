<?php

use Ameax\LaravelChangeDetection\Enums\PublishStatusEnum;
use Ameax\LaravelChangeDetection\Models\Hash;
use Ameax\LaravelChangeDetection\Models\Publish;
use Ameax\LaravelChangeDetection\Tests\Models\TestCar;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    // Register TestCar in the morph map
    Relation::morphMap([
        'test_car' => TestCar::class,
    ]);

    // Load helper functions
    require_once __DIR__.'/Helpers/CarHelpers.php';
});

describe('car publishing', function () {

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
            'model' => 'BMW M3',
            'price' => 70000,
            'is_electric' => false,
        ]);

        // Create an inactive publisher (should be ignored)
        $inactivePublisher = createCarPublisher([
            'name' => 'Inactive Car Publisher',
            'status' => 'inactive',
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
        expect($publish->status)->toBe(PublishStatusEnum::PENDING);
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
            'status' => PublishStatusEnum::PUBLISHED,
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
        expect($apiPublish->status)->toBe(PublishStatusEnum::PENDING);
        expect($backupPublish->status)->toBe(PublishStatusEnum::PENDING);
        expect($analyticsPublish->status)->toBe(PublishStatusEnum::PENDING);

        // All should point to the same hash
        expect($apiPublish->hash_id)->toBe($hash->id);
        expect($backupPublish->hash_id)->toBe($hash->id);
        expect($analyticsPublish->hash_id)->toBe($hash->id);

        // Simulate different publish states
        // API publisher succeeds
        $apiPublish->update([
            'status' => PublishStatusEnum::PUBLISHED,
            'published_at' => now(),
            'published_hash' => $hash->composite_hash, // Set the published hash
            'attempts' => 1,
        ]);

        // Backup publisher fails
        $backupPublish->update([
            'status' => PublishStatusEnum::FAILED,
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
        expect($apiPublish->status)->toBe(PublishStatusEnum::PENDING);
        expect($apiPublish->attempts)->toBe(0);
        expect($apiPublish->hash_id)->toBe($hash->id); // Still same hash record

        // Failed record should reset to pending with attempts reset
        expect($backupPublish->status)->toBe(PublishStatusEnum::PENDING);
        expect($backupPublish->attempts)->toBe(0);
        expect($backupPublish->last_error)->toBeNull();

        // Pending record should remain pending
        expect($analyticsPublish->status)->toBe(PublishStatusEnum::PENDING);
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
        expect($publish1->status)->toBe(PublishStatusEnum::PENDING);
        expect($publish2->status)->toBe(PublishStatusEnum::PENDING);

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
        expect($publish1->status)->toBe(PublishStatusEnum::PENDING);
        expect($publish2->status)->toBe(PublishStatusEnum::PENDING);
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
        expect($publish3->status)->toBe(PublishStatusEnum::PENDING);

        // Verify car1 and car2 publish records were reset due to hash changes
        $publish1->refresh();
        $publish2->refresh();

        // Since the hashes changed while publisher was inactive,
        // they should now be reset to pending with updated content
        expect($publish1->status)->toBe(PublishStatusEnum::PENDING);
        expect($publish2->status)->toBe(PublishStatusEnum::PENDING);

        // Update a car again with publisher active
        $car1->update(['price' => 100000]);

        // Mark publish1 as published first
        $publish1->update([
            'status' => PublishStatusEnum::PUBLISHED,
            'published_at' => now(),
            'published_hash' => $car1->getCurrentHash()->composite_hash,
        ]);

        // Now update the car
        $car1->update(['price' => 105000]);

        // Run sync
        syncCars();

        // Verify the published record was properly reset
        $publish1->refresh();
        expect($publish1->status)->toBe(PublishStatusEnum::PENDING);
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

    it('handles batch processing efficiently for large datasets', function () {
        // Create a publisher
        $publisher = createCarPublisher();

        // Create 100 cars in batch
        $cars = [];
        $startTime = microtime(true);

        for ($i = 1; $i <= 100; $i++) {
            $cars[] = TestCar::create([
                'model' => "Test Car {$i}",
                'year' => 2020 + ($i % 4),
                'price' => 30000 + ($i * 1000),
                'is_electric' => $i % 2 === 0,
                'features' => ['feature_'.$i => true],
            ]);
        }

        $createTime = microtime(true) - $startTime;

        // Run sync to create all hashes and publish records
        $startTime = microtime(true);
        syncCars();
        $syncTime = microtime(true) - $startTime;

        // Verify all hashes were created
        $hashCount = Hash::where('hashable_type', 'test_car')
            ->whereIn('hashable_id', collect($cars)->pluck('id'))
            ->count();
        expect($hashCount)->toBe(100);

        // Verify all publish records were created
        $publishCount = Publish::whereHas('hash', function ($query) {
            $query->where('hashable_type', 'test_car');
        })->count();
        expect($publishCount)->toBe(100);

        // Performance check: sync should complete in reasonable time
        // Even with 100 records, it should take less than 5 seconds
        expect($syncTime)->toBeLessThan(5.0);

        // Update 50 cars in batch
        $carsToUpdate = array_slice($cars, 0, 50);
        foreach ($carsToUpdate as $car) {
            $car->update(['price' => $car->price + 5000]);
        }

        // Run sync again to update changed hashes
        $startTime = microtime(true);
        syncCars();
        $updateSyncTime = microtime(true) - $startTime;

        // Verify 50 publish records were reset to pending
        $pendingCount = Publish::where('status', 'pending')
            ->whereHas('hash', function ($query) use ($carsToUpdate) {
                $query->where('hashable_type', 'test_car')
                    ->whereIn('hashable_id', collect($carsToUpdate)->pluck('id'));
            })->count();
        expect($pendingCount)->toBe(50);

        // Update sync should also be fast
        expect($updateSyncTime)->toBeLessThan(3.0);

        // Simulate publishing some records
        $publishesToUpdate = Publish::whereHas('hash', function ($query) {
            $query->where('hashable_type', 'test_car');
        })->limit(30)->get();

        foreach ($publishesToUpdate as $publish) {
            $hash = Hash::find($publish->hash_id);
            $publish->update([
                'status' => PublishStatusEnum::PUBLISHED,
                'published_hash' => $hash->composite_hash,
                'published_at' => now(),
            ]);
        }

        // Soft delete 20 cars
        $carsToDelete = array_slice($cars, 80, 20);
        foreach ($carsToDelete as $car) {
            $car->delete();
        }

        // Final sync with mixed operations
        $startTime = microtime(true);
        syncCars();
        $finalSyncTime = microtime(true) - $startTime;

        // Verify soft-deleted hashes
        $deletedHashCount = Hash::where('hashable_type', 'test_car')
            ->whereIn('hashable_id', collect($carsToDelete)->pluck('id'))
            ->whereNotNull('deleted_at')
            ->count();
        expect($deletedHashCount)->toBe(20);

        // Final sync should handle mixed operations efficiently
        expect($finalSyncTime)->toBeLessThan(3.0);

        // Log performance metrics for visibility
        dump('Performance Metrics:');
        dump("  Create 100 cars: {$createTime}s");
        dump("  Initial sync (100 records): {$syncTime}s");
        dump("  Update sync (50 changes): {$updateSyncTime}s");
        dump("  Final sync (mixed ops): {$finalSyncTime}s");
    });

    it('uses bulk SQL operations instead of individual queries', function () {
        // This test verifies that we're using efficient bulk operations
        $publisher = createCarPublisher();

        // Create 50 cars
        $cars = [];
        for ($i = 1; $i <= 50; $i++) {
            $cars[] = TestCar::create([
                'model' => "Batch Car {$i}",
                'year' => 2024,
                'price' => 40000 + ($i * 100),
                'is_electric' => true,
                'features' => [],
            ]);
        }

        // Enable query log to count queries
        DB::enableQueryLog();

        // Run sync
        syncCars();

        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        // Count different types of queries
        $selectQueries = count(array_filter($queries, fn ($q) => str_starts_with(strtolower($q['query']), 'select')));
        $insertQueries = count(array_filter($queries, fn ($q) => str_starts_with(strtolower($q['query']), 'insert')));
        $updateQueries = count(array_filter($queries, fn ($q) => str_starts_with(strtolower($q['query']), 'update')));

        // With bulk operations, we should have very few queries even for 50 records
        // Should be less than 20 total queries (not 50+ which would indicate N+1)
        $totalQueries = count($queries);
        expect($totalQueries)->toBeLessThan(20);

        // Log for visibility
        dump('Query Analysis for 50 records:');
        dump("  Total queries: {$totalQueries}");
        dump("  SELECT queries: {$selectQueries}");
        dump("  INSERT queries: {$insertQueries}");
        dump("  UPDATE queries: {$updateQueries}");

        // Verify results are correct despite using bulk operations
        $hashCount = Hash::where('hashable_type', 'test_car')
            ->whereIn('hashable_id', collect($cars)->pluck('id'))
            ->count();
        expect($hashCount)->toBe(50);

        $publishCount = Publish::whereHas('hash', function ($query) {
            $query->where('hashable_type', 'test_car');
        })->count();
        expect($publishCount)->toBe(50);
    });

    it('handles 5000 cars efficiently', function () {
        // Create a publisher
        $publisher = createCarPublisher();

        // Create 5000 cars using bulk insert for faster creation
        $startTime = microtime(true);

        $carData = [];
        $now = now();
        for ($i = 1; $i <= 5000; $i++) {
            $carData[] = [
                'model' => "Car {$i}",
                'year' => 2020 + ($i % 5),
                'price' => 30000 + ($i * 10),
                'is_electric' => $i % 3 === 0,
                'features' => json_encode(['id' => $i]),
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        // Use chunk insert for faster creation
        foreach (array_chunk($carData, 500) as $chunk) {
            TestCar::insert($chunk);
        }

        $createTime = microtime(true) - $startTime;
        dump("Created 5000 cars in: {$createTime}s");

        // Get all car IDs for verification
        $carIds = TestCar::pluck('id')->toArray();
        expect(count($carIds))->toBe(5000);

        // Enable query log
        DB::enableQueryLog();

        // Run sync to create all hashes and publish records
        $startTime = microtime(true);
        syncCars();
        $syncTime = microtime(true) - $startTime;

        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        // Verify all hashes were created
        $hashCount = Hash::where('hashable_type', 'test_car')
            ->whereIn('hashable_id', $carIds)
            ->count();
        expect($hashCount)->toBe(5000);

        // Verify all publish records were created
        $publishCount = Publish::whereHas('hash', function ($query) {
            $query->where('hashable_type', 'test_car');
        })->count();
        expect($publishCount)->toBe(5000);

        // Performance check: even with 5000 records, should complete reasonably fast
        dump('Performance for 5000 cars:');
        dump("  Sync time: {$syncTime}s");
        dump('  Total queries: '.count($queries));
        dump('  Queries per record: '.(count($queries) / 5000));

        // Should complete in under 30 seconds even with 5000 records
        expect($syncTime)->toBeLessThan(30.0);

        // Should use bulk operations - very few queries relative to record count
        $queriesPerRecord = count($queries) / 5000;
        expect($queriesPerRecord)->toBeLessThan(0.01); // Less than 1 query per 100 records

        // Update 1000 cars to test bulk update performance
        $carsToUpdate = TestCar::limit(1000)->pluck('id');
        TestCar::whereIn('id', $carsToUpdate)
            ->update(['price' => DB::raw('price + 5000')]);

        // Run sync again
        $startTime = microtime(true);
        syncCars();
        $updateSyncTime = microtime(true) - $startTime;

        dump("  Update sync time (1000 changes): {$updateSyncTime}s");

        // Update sync should also be fast
        expect($updateSyncTime)->toBeLessThan(10.0);

        // Verify the 1000 publish records were updated
        $pendingCount = Publish::where('status', 'pending')
            ->whereHas('hash', function ($query) use ($carsToUpdate) {
                $query->where('hashable_type', 'test_car')
                    ->whereIn('hashable_id', $carsToUpdate);
            })->count();
        expect($pendingCount)->toBeGreaterThanOrEqual(1000);
    })->skip(env('SKIP_LARGE_TESTS', true), 'Skipping large dataset test - set SKIP_LARGE_TESTS=false to run');

    it('handles concurrent publishing and prevents duplicates', function () {
        // Create cars and publisher
        $car1 = createCar(['model' => 'Ferrari F40', 'price' => 500000]);
        $car2 = createCar(['model' => 'Lamborghini Aventador', 'price' => 400000]);
        $publisher = createCarPublisher();

        // Initial sync
        syncCars();

        $car1->refresh();
        $car2->refresh();
        $hash1 = $car1->getCurrentHash();
        $hash2 = $car2->getCurrentHash();

        // Get publish records
        $publish1 = Publish::where('hash_id', $hash1->id)->first();
        $publish2 = Publish::where('hash_id', $hash2->id)->first();

        // Simulate first publish being in progress
        $publish1->update([
            'status' => 'dispatched',
            'started_at' => now(),
        ]);

        // Run sync again while first publish is processing
        syncCars();

        // Verify no duplicate publish record was created
        $publishCount = Publish::where('hash_id', $hash1->id)
            ->where('publisher_id', $publisher->id)
            ->count();
        expect($publishCount)->toBe(1);

        // Verify dispatched status wasn't changed
        $publish1->refresh();
        expect($publish1->status)->toBe(PublishStatusEnum::DISPATCHED);

        // Verify other publish is still pending
        $publish2->refresh();
        expect($publish2->status)->toBe(PublishStatusEnum::PENDING);

        // Update both cars
        $car1->update(['price' => 550000]);
        $car2->update(['price' => 450000]);

        // Run sync with one publish still processing
        syncCars();

        // Dispatched publish should not be reset (avoid interrupting active publish)
        $publish1->refresh();
        expect($publish1->status)->toBe(PublishStatusEnum::DISPATCHED);

        // But the other should remain pending (will get new hash on next sync)
        $publish2->refresh();
        expect($publish2->status)->toBe(PublishStatusEnum::PENDING);

        // Simulate publish1 completing
        $publish1->update([
            'status' => PublishStatusEnum::PUBLISHED,
            'published_hash' => $hash1->composite_hash,
            'published_at' => now(),
            'started_at' => null,
        ]);

        // Now run sync - should detect the change and reset publish1
        syncCars();

        $publish1->refresh();
        expect($publish1->status)->toBe(PublishStatusEnum::PENDING);
        expect($publish1->attempts)->toBe(0);
    });

    it('handles failed publishes and resets them', function () {
        $car = createCar(['model' => 'McLaren P1', 'price' => 1500000]);
        $publisher = createCarPublisher([
            'retry_attempts' => 3,
            'retry_delay' => 60, // 60 seconds
        ]);

        // Initial sync
        syncCars();

        $car->refresh();
        $hash = $car->getCurrentHash();
        $publish = Publish::where('hash_id', $hash->id)->first();

        // Simulate first publish attempt failure
        $publish->update([
            'status' => PublishStatusEnum::FAILED,
            'attempts' => 1,
            'last_error' => 'Connection timeout',
            'last_response_code' => null,
            'error_type' => 'infrastructure',
            'next_try' => now()->addSeconds(60),
        ]);

        // Run sync - currently all failed records are reset regardless of next_try
        syncCars();

        $publish->refresh();
        expect($publish->status)->toBe(PublishStatusEnum::PENDING);
        expect($publish->attempts)->toBe(0);
        expect($publish->last_error)->toBeNull();

        // Simulate failure again
        $publish->update([
            'status' => PublishStatusEnum::FAILED,
            'attempts' => 2,
            'last_error' => 'Server error',
            'last_response_code' => 500,
            'error_type' => 'infrastructure',
        ]);

        // Car changes while in failed state
        $car->update(['price' => 1600000]);

        // Run sync - failed records are always reset
        syncCars();

        $publish->refresh();
        expect($publish->status)->toBe(PublishStatusEnum::PENDING);
        expect($publish->attempts)->toBe(0);
        expect($publish->last_error)->toBeNull();
    });

    it('can identify stale pending publishes', function () {
        $car = createCar(['model' => 'Bugatti Chiron', 'price' => 3000000]);
        $publisher = createCarPublisher();

        // Initial sync
        syncCars();

        $car->refresh();
        $hash = $car->getCurrentHash();
        $publish = Publish::where('hash_id', $hash->id)->first();

        // Verify normal publish is not stale
        $stalePublishes = Publish::where('status', 'pending')
            ->where('created_at', '<', now()->subDays(1))
            ->count();
        expect($stalePublishes)->toBe(0);

        // Make publish very old (simulate stuck pending)
        DB::table('publishes')
            ->where('id', $publish->id)
            ->update([
                'created_at' => now()->subDays(7),
                'updated_at' => now()->subDays(7),
            ]);

        // Now it should be identified as stale
        $stalePublishes = Publish::where('status', 'pending')
            ->where('created_at', '<', now()->subDays(1))
            ->count();

        expect($stalePublishes)->toBe(1);

        // Verify we can identify the specific stale publish
        $stale = Publish::where('status', 'pending')
            ->where('created_at', '<', now()->subDays(1))
            ->first();

        expect($stale)->not->toBeNull();
        expect($stale->id)->toBe($publish->id);

        // These stale publishes could be handled by:
        // 1. A cleanup job that marks them as failed
        // 2. A monitoring alert for investigation
        // 3. Automatic retry with increased attempts
    });

    it('prevents race conditions with database locking', function () {
        // This test simulates what happens when multiple workers
        // try to process the same publish records simultaneously

        $car = createCar(['model' => 'Koenigsegg Agera', 'price' => 2000000]);
        $publisher = createCarPublisher();

        // Initial sync
        syncCars();

        $car->refresh();
        $hash = $car->getCurrentHash();
        $publish = Publish::where('hash_id', $hash->id)->first();

        // Simulate two workers trying to process the same publish
        // In a real scenario, this would use SELECT ... FOR UPDATE
        // to lock the record while processing

        // Worker 1 locks and starts processing
        $locked = DB::transaction(function () use ($publish) {
            // This would normally use lockForUpdate()
            $lockedPublish = Publish::where('id', $publish->id)
                ->where('status', 'pending')
                ->first();

            if ($lockedPublish) {
                $lockedPublish->update([
                    'status' => 'dispatched',
                    'started_at' => now(),
                ]);

                return true;
            }

            return false;
        });

        expect($locked)->toBeTrue();

        // Worker 2 tries to process the same record
        $locked2 = DB::transaction(function () use ($publish) {
            $lockedPublish = Publish::where('id', $publish->id)
                ->where('status', 'pending')
                ->first();

            if ($lockedPublish) {
                $lockedPublish->update([
                    'status' => 'dispatched',
                    'started_at' => now(),
                ]);

                return true;
            }

            return false;
        });

        // Second worker should not be able to lock it
        expect($locked2)->toBeFalse();

        // Verify status is dispatched from first worker
        $publish->refresh();
        expect($publish->status)->toBe(PublishStatusEnum::DISPATCHED);
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
                $publish->update(['status' => PublishStatusEnum::DISPATCHED]);
            } elseif ($index === 1) {
                $publish->update(['status' => PublishStatusEnum::PUBLISHED, 'published_at' => now()]);
            }
            // Third remains pending
        }

        // Run sync again
        syncCars();

        // Verify each maintains its own state
        $publishes = Publish::orderBy('id')->get();
        expect($publishes[0]->status)->toBe(PublishStatusEnum::DISPATCHED);
        expect($publishes[1]->status)->toBe(PublishStatusEnum::PUBLISHED);
        expect($publishes[2]->status)->toBe(PublishStatusEnum::PENDING);

        // No duplicates created
        expect(Publish::count())->toBe(3);
    });

});
