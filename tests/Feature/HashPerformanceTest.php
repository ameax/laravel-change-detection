<?php

use Ameax\LaravelChangeDetection\Models\Hash;
use Ameax\LaravelChangeDetection\Models\HashDependent;
use Ameax\LaravelChangeDetection\Services\BulkHashProcessor;
use Ameax\LaravelChangeDetection\Services\ChangeDetector;
use Ameax\LaravelChangeDetection\Services\MySQLHashCalculator;
use Ameax\LaravelChangeDetection\Tests\Models\TestWeatherStation;
use Ameax\LaravelChangeDetection\Tests\Models\TestWindvane;
use Ameax\LaravelChangeDetection\Tests\Models\TestAnemometer;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    Relation::morphMap([
        'test_weather_station' => TestWeatherStation::class,
        'test_windvane' => TestWindvane::class,
        'test_anemometer' => TestAnemometer::class,
    ]);
});

describe('hash system performance optimization', function () {
    // 1. Bulk Hash Processing for Large Dataset
    it('processes 1000+ weather stations efficiently', function () {
        $startMemory = memory_get_usage(true);
        $startTime = microtime(true);

        // Create large dataset
        $stations = createBulkWeatherStations(1000);

        $creationTime = microtime(true) - $startTime;
        $creationMemory = memory_get_usage(true) - $startMemory;

        // Process all hashes
        $publisher = createPublisherForModel('test_weather_station');
        $processingStart = microtime(true);

        runSyncAutoDiscover();

        $processingTime = microtime(true) - $processingStart;
        $peakMemory = memory_get_peak_usage(true);

        // Verify all hashes created
        expectActiveHashCountForType('test_weather_station', 1000);

        // Performance assertions
        expect($processingTime)->toBeLessThan(30); // Should process 1000 records in under 30 seconds
        expect($peakMemory / 1024 / 1024)->toBeLessThan(256); // Should use less than 256MB

        // Log performance metrics
        logPerformanceMetrics([
            'records' => 1000,
            'creation_time' => $creationTime,
            'processing_time' => $processingTime,
            'peak_memory_mb' => $peakMemory / 1024 / 1024,
            'records_per_second' => 1000 / $processingTime,
        ]);
    });

    // 2. Optimal Batch Size Discovery
    it('identifies optimal batch size for hash processing', function () {
        $batchSizes = [100, 250, 500, 1000, 2000];
        $results = [];

        foreach ($batchSizes as $batchSize) {
            // Clean slate
            cleanupAllTestData();

            // Create dataset
            $stations = createBulkWeatherStations(500);
            createPublisherForModel('test_weather_station');

            // Configure batch size
            $processor = app(BulkHashProcessor::class);
            $processor->setBatchSize($batchSize);

            // Measure processing
            $startTime = microtime(true);
            $startMemory = memory_get_usage(true);

            $processor->processChangedModels(TestWeatherStation::class);

            $results[$batchSize] = [
                'time' => microtime(true) - $startTime,
                'memory' => memory_get_peak_usage(true) - $startMemory,
                'efficiency' => 500 / (microtime(true) - $startTime), // records per second
            ];
        }

        // Find optimal batch size
        $optimalBatch = array_key_first(array_filter($results, function ($metrics) use ($results) {
            $avgEfficiency = array_sum(array_column($results, 'efficiency')) / count($results);
            return $metrics['efficiency'] > $avgEfficiency;
        }));

        expect($optimalBatch)->toBeGreaterThanOrEqual(500);
        expect($optimalBatch)->toBeLessThanOrEqual(1000);

        logBatchSizeComparison($results);
    });

    // 3. Memory Usage During Incremental Processing
    it('manages memory efficiently during incremental hash updates', function () {
        // Initial large dataset
        $stations = createBulkWeatherStationsWithSensors(500);
        createPublisherForModel('test_weather_station');
        runSyncAutoDiscover();

        $memorySnapshots = [];

        // Simulate incremental updates
        for ($i = 0; $i < 10; $i++) {
            $startMemory = memory_get_usage(true);

            // Update 10% of stations
            updateRandomStations(50);
            runSyncAutoDiscover();

            $memorySnapshots[] = memory_get_usage(true) - $startMemory;

            // Force garbage collection
            gc_collect_cycles();
        }

        // Memory should remain stable (no leaks)
        $avgMemory = array_sum($memorySnapshots) / count($memorySnapshots);
        $maxMemory = max($memorySnapshots);

        expect($maxMemory)->toBeLessThan($avgMemory * 1.5); // Max should not exceed 150% of average
        expect($memorySnapshots[9])->toBeLessThanOrEqual($memorySnapshots[0] * 1.1); // Last update similar to first

        logMemoryPattern($memorySnapshots);
    });

    // 4. Query Performance with Database Indexes
    it('demonstrates index impact on hash query performance', function () {
        // Create substantial dataset
        createBulkWeatherStationsWithSensors(1000);
        createPublisherForModel('test_weather_station');
        runSyncAutoDiscover();

        // Test without additional indexes
        $withoutIndexTime = measureQueryPerformance(function () {
            $detector = app(ChangeDetector::class);
            $detector->detectChangedModelIds(TestWeatherStation::class, 100);
        });

        // Add composite index for better performance
        DB::statement('CREATE INDEX idx_hash_lookup ON hashes (hashable_type, hashable_id, deleted_at)');
        DB::statement('CREATE INDEX idx_hash_composite ON hashes (hashable_type, composite_hash, deleted_at)');

        // Test with indexes
        $withIndexTime = measureQueryPerformance(function () {
            $detector = app(ChangeDetector::class);
            $detector->detectChangedModelIds(TestWeatherStation::class, 100);
        });

        // Drop test indexes
        DB::statement('DROP INDEX idx_hash_lookup ON hashes');
        DB::statement('DROP INDEX idx_hash_composite ON hashes');

        // Indexes should improve performance by at least 30%
        $improvement = (($withoutIndexTime - $withIndexTime) / $withoutIndexTime) * 100;
        expect($improvement)->toBeGreaterThan(30);

        logIndexPerformance($withoutIndexTime, $withIndexTime, $improvement);
    });

    // 5. Cross-Database Query Performance
    it('measures cross-database operation overhead', function () {
        // Skip if not configured for cross-database
        if (!isConfiguredForCrossDatabase()) {
            $this->markTestSkipped('Cross-database not configured');
        }

        $stations = createBulkWeatherStations(500);
        createPublisherForModel('test_weather_station');

        // Measure same-database performance
        $sameDatabaseTime = measureQueryPerformance(function () {
            runSyncAutoDiscover();
        });

        // Configure cross-database
        configureCrossDatabaseHashes();

        // Measure cross-database performance
        $crossDatabaseTime = measureQueryPerformance(function () {
            runSyncAutoDiscover();
        });

        // Cross-database overhead should be less than 50%
        $overhead = (($crossDatabaseTime - $sameDatabaseTime) / $sameDatabaseTime) * 100;
        expect($overhead)->toBeLessThan(50);

        logCrossDatabasePerformance($sameDatabaseTime, $crossDatabaseTime, $overhead);
    });

    // 6. Concurrent Hash Processing Performance
    it('handles concurrent hash operations efficiently', function () {
        $stations = createBulkWeatherStationsWithSensors(200);
        createPublisherForModel('test_weather_station');
        runSyncAutoDiscover();

        $concurrentJobs = [];
        $startTime = microtime(true);

        // Simulate concurrent operations
        for ($i = 0; $i < 5; $i++) {
            $concurrentJobs[] = [
                'type' => $i % 2 == 0 ? 'update' : 'delete',
                'start' => microtime(true),
                'stations' => array_slice($stations, $i * 40, 40),
            ];

            if ($i % 2 == 0) {
                // Update operations
                updateStationsData(array_slice($stations, $i * 40, 40));
            } else {
                // Delete operations
                deleteStations(array_slice($stations, $i * 40, 40));
            }
        }

        // Process all changes
        runSyncAutoDiscover();

        $totalTime = microtime(true) - $startTime;

        // Should handle concurrent operations efficiently
        expect($totalTime)->toBeLessThan(10); // 5 concurrent operations in under 10 seconds

        // Check for deadlocks
        $deadlocks = DB::select("SHOW ENGINE INNODB STATUS");
        expect($deadlocks)->not->toContain('LATEST DETECTED DEADLOCK');

        logConcurrentPerformance($concurrentJobs, $totalTime);
    });

    // 7. Deep Dependency Chain Performance
    it('efficiently processes deep dependency chains', function () {
        // Create stations with multiple sensor layers
        $complexStations = createComplexWeatherNetwork(100, 5); // 100 stations, 5 sensors each
        createPublisherForModel('test_weather_station');

        $startTime = microtime(true);
        $startMemory = memory_get_usage(true);

        runSyncAutoDiscover();

        $processingTime = microtime(true) - $startTime;
        $memoryUsed = memory_get_peak_usage(true) - $startMemory;

        // Verify dependency chains built
        $stationHash = getStationHash($complexStations[0]->id);
        $dependentCount = HashDependent::where('hash_id', $stationHash->id)->count();

        expect($dependentCount)->toBeGreaterThan(0);
        expect($processingTime)->toBeLessThan(20); // Complex dependencies in under 20 seconds
        expect($memoryUsed / 1024 / 1024)->toBeLessThan(128); // Less than 128MB for dependencies

        logDependencyPerformance($processingTime, $memoryUsed, $dependentCount);
    });

    // 8. Bulk Delete Performance
    it('efficiently handles bulk deletion of 1000+ records', function () {
        $stations = createBulkWeatherStationsWithSensors(1000);
        createPublisherForModel('test_weather_station');
        runSyncAutoDiscover();

        expectActiveHashCountForType('test_weather_station', 1000);

        $startTime = microtime(true);

        // Bulk delete all stations
        TestWeatherStation::whereIn('id', array_column($stations, 'id'))->delete();
        runSyncAutoDiscover();

        $deletionTime = microtime(true) - $startTime;

        // All should be soft-deleted
        expectActiveHashCountForType('test_weather_station', 0);
        expect(Hash::where('hashable_type', 'test_weather_station')
            ->whereNotNull('deleted_at')->count())->toBe(1000);

        // Bulk deletion should be fast
        expect($deletionTime)->toBeLessThan(15);

        // Test purge performance
        $purgeStart = microtime(true);
        runSyncAutoDiscover(['--purge' => true]);
        $purgeTime = microtime(true) - $purgeStart;

        expect($purgeTime)->toBeLessThan(10);
        expectTotalHashCountForType('test_weather_station', 0);

        logBulkDeletePerformance($deletionTime, $purgeTime);
    });

    // 9. MySQL-Specific Hash Calculation Performance
    it('optimizes MySQL hash calculation for large datasets', function () {
        $calculator = app(MySQLHashCalculator::class);

        // Create dataset
        $stations = createBulkWeatherStations(2000);
        $stationIds = array_column($stations, 'id');

        // Test single hash calculation
        $singleStart = microtime(true);
        foreach (array_slice($stationIds, 0, 100) as $id) {
            $calculator->calculateAttributeHash(TestWeatherStation::find($id));
        }
        $singleTime = microtime(true) - $singleStart;

        // Test bulk hash calculation
        $bulkStart = microtime(true);
        $calculator->calculateAttributeHashBulk(
            TestWeatherStation::class,
            array_slice($stationIds, 100, 100)
        );
        $bulkTime = microtime(true) - $bulkStart;

        // Bulk should be at least 5x faster
        $speedup = $singleTime / $bulkTime;
        expect($speedup)->toBeGreaterThan(5);

        logHashCalculationPerformance($singleTime, $bulkTime, $speedup);
    });

    // 10. Incremental vs Full Sync Performance
    it('compares incremental sync with full resync performance', function () {
        // Initial dataset
        $stations = createBulkWeatherStationsWithSensors(500);
        createPublisherForModel('test_weather_station');

        // Full initial sync
        $fullSyncStart = microtime(true);
        runSyncAutoDiscover();
        $fullSyncTime = microtime(true) - $fullSyncStart;

        // Make 10% changes
        updateRandomStations(50);

        // Incremental sync
        $incrementalStart = microtime(true);
        runSyncAutoDiscover();
        $incrementalTime = microtime(true) - $incrementalStart;

        // Force full resync by marking all as changed
        DB::table('hashes')
            ->where('hashable_type', 'test_weather_station')
            ->update(['attribute_hash' => 'force_change']);

        $forcedFullStart = microtime(true);
        runSyncAutoDiscover();
        $forcedFullTime = microtime(true) - $forcedFullStart;

        // Incremental should be much faster than full
        expect($incrementalTime)->toBeLessThan($fullSyncTime * 0.3); // Less than 30% of full sync
        expect($forcedFullTime)->toBeGreaterThan($incrementalTime * 3); // Full resync 3x slower

        logSyncComparison($fullSyncTime, $incrementalTime, $forcedFullTime);
    });

    // 11. Query Optimization with EXPLAIN Analysis
    it('analyzes and optimizes complex query execution plans', function () {
        createBulkWeatherStationsWithSensors(500);
        createPublisherForModel('test_weather_station');
        runSyncAutoDiscover();

        $detector = app(ChangeDetector::class);

        // Analyze query execution plan
        $query = getChangeDetectionQuery(TestWeatherStation::class);
        $explainResult = DB::select("EXPLAIN {$query}");

        $hasTableScan = false;
        $hasIndexUsage = false;

        foreach ($explainResult as $row) {
            if ($row->type === 'ALL') {
                $hasTableScan = true;
            }
            if (!empty($row->key)) {
                $hasIndexUsage = true;
            }
        }

        // Should use indexes, not table scans
        expect($hasTableScan)->toBeFalse();
        expect($hasIndexUsage)->toBeTrue();

        logQueryPlan($explainResult);
    });

    // 12. Memory Leak Detection During Long-Running Process
    it('detects and prevents memory leaks in long-running operations', function () {
        $memoryCheckpoints = [];
        $iterations = 20;

        for ($i = 0; $i < $iterations; $i++) {
            // Create and process batch
            $stations = createBulkWeatherStations(100);
            createPublisherForModel('test_weather_station');
            runSyncAutoDiscover();

            // Update and resync
            updateRandomStations(50);
            runSyncAutoDiscover();

            // Record memory after garbage collection
            gc_collect_cycles();
            $memoryCheckpoints[] = memory_get_usage(true);

            // Clean up for next iteration
            TestWeatherStation::whereIn('id', array_column($stations, 'id'))->forceDelete();
            Hash::where('hashable_type', 'test_weather_station')->forceDelete();
        }

        // Calculate memory growth
        $memoryGrowth = $memoryCheckpoints[$iterations - 1] - $memoryCheckpoints[0];
        $avgGrowthPerIteration = $memoryGrowth / $iterations;

        // Should not leak more than 1MB per iteration
        expect($avgGrowthPerIteration / 1024 / 1024)->toBeLessThan(1);

        // Memory should stabilize (last 5 iterations should be similar)
        $lastFive = array_slice($memoryCheckpoints, -5);
        $variance = max($lastFive) - min($lastFive);
        expect($variance / 1024 / 1024)->toBeLessThan(5); // Less than 5MB variance

        logMemoryLeakAnalysis($memoryCheckpoints, $memoryGrowth);
    });

    // 13. Batch Size Impact on Database Lock Time
    it('measures database lock duration for different batch sizes', function () {
        $batchSizes = [50, 100, 500, 1000];
        $lockMetrics = [];

        foreach ($batchSizes as $batchSize) {
            cleanupAllTestData();

            $stations = createBulkWeatherStationsWithSensors(1000);
            createPublisherForModel('test_weather_station');

            $processor = app(BulkHashProcessor::class);
            $processor->setBatchSize($batchSize);

            // Monitor lock time
            $lockStart = microtime(true);
            $locks = [];

            // Start monitoring in separate connection
            $monitorConnection = DB::connection('mysql');

            $processor->processChangedModels(TestWeatherStation::class);

            $lockTime = microtime(true) - $lockStart;

            // Check for lock waits
            $lockWaits = DB::select("
                SELECT COUNT(*) as wait_count
                FROM information_schema.INNODB_LOCK_WAITS
            ");

            $lockMetrics[$batchSize] = [
                'total_time' => $lockTime,
                'lock_waits' => $lockWaits[0]->wait_count ?? 0,
                'avg_lock_time' => $lockTime / (1000 / $batchSize),
            ];
        }

        // Larger batches should have better lock efficiency
        expect($lockMetrics[1000]['avg_lock_time'])
            ->toBeLessThan($lockMetrics[50]['avg_lock_time']);

        logLockMetrics($lockMetrics);
    });

    // 14. Composite Hash Calculation Performance
    it('optimizes composite hash calculation for complex dependencies', function () {
        // Create complex network with dependencies
        $stations = [];
        for ($i = 0; $i < 100; $i++) {
            $station = createStationInBayern();

            // Add multiple sensors
            for ($j = 0; $j < 10; $j++) {
                createWindvaneForStation($station->id, $j * 36);
                createAnemometerForStation($station->id, 20 + $j);
            }

            $stations[] = $station;
        }

        createPublisherForModel('test_weather_station');

        $startTime = microtime(true);
        $startMemory = memory_get_usage(true);

        runSyncAutoDiscover();

        $processingTime = microtime(true) - $startTime;
        $memoryUsed = memory_get_peak_usage(true) - $startMemory;

        // Verify composite hashes calculated
        $sampleHash = getStationHash($stations[0]->id);
        expect($sampleHash->composite_hash)->not->toBeNull();
        expect($sampleHash->has_dependencies_built)->toBeTrue();

        // Performance targets for complex dependencies
        expect($processingTime)->toBeLessThan(30); // 100 stations with 2000 sensors in 30s
        expect($memoryUsed / 1024 / 1024)->toBeLessThan(256);

        logCompositeHashPerformance($processingTime, $memoryUsed, count($stations) * 20);
    });

    // 15. Performance Under Resource Constraints
    it('maintains acceptable performance under memory constraints', function () {
        // Set memory limit (if possible in test environment)
        $originalLimit = ini_get('memory_limit');
        ini_set('memory_limit', '128M');

        try {
            $stations = createBulkWeatherStationsWithSensors(500, 1);
            createPublisherForModel('test_weather_station');

            $startTime = microtime(true);

            // Should complete without memory exhaustion
            runSyncAutoDiscover();

            $processingTime = microtime(true) - $startTime;

            expectActiveHashCountForType('test_weather_station', 500);
            expect($processingTime)->toBeLessThan(60); // Should complete even with constraints

            $peakMemory = memory_get_peak_usage(true) / 1024 / 1024;
            expect($peakMemory)->toBeLessThan(128); // Should stay within limit

        } finally {
            ini_set('memory_limit', $originalLimit);
        }

        logConstrainedPerformance($processingTime, $peakMemory);
    });
})->skip();
