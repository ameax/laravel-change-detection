<?php

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
    require_once __DIR__.'/../Helpers/CarHelpers.php';
});

describe('car bulk publishing performance', function () {

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
                'status' => 'published',
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

});
