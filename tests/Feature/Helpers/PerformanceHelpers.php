<?php

use Ameax\LaravelChangeDetection\Services\BulkHashProcessor;
use Ameax\LaravelChangeDetection\Services\ChangeDetector;
use Ameax\LaravelChangeDetection\Tests\Models\TestWeatherStation;
use Ameax\LaravelChangeDetection\Tests\Models\TestWindvane;
use Ameax\LaravelChangeDetection\Tests\Models\TestAnemometer;
use Ameax\LaravelChangeDetection\Models\Hash;
use Ameax\LaravelChangeDetection\Models\HashDependent;
use Ameax\LaravelChangeDetection\Models\Publish;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

// ===== BULK DATA CREATION HELPERS =====

function createBulkWeatherStations(int $count): array
{
    $stations = [];
    $batchSize = 100;

    // Disable events for performance
    return TestWeatherStation::withoutEvents(function () use ($count, $batchSize, &$stations) {
        for ($i = 0; $i < $count; $i += $batchSize) {
            $batchData = [];
            $currentBatchSize = min($batchSize, $count - $i);

            for ($j = 0; $j < $currentBatchSize; $j++) {
                $batchData[] = [
                    'name' => 'Station '.($i + $j),
                    'location' => 'Bayern',
                    'latitude' => 48.1351 + (($i + $j) * 0.001),
                    'longitude' => 11.5820 + (($i + $j) * 0.001),
                    'status' => 'active',
                    'is_operational' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }

            TestWeatherStation::insert($batchData);

            // Get inserted records
            $insertedStations = TestWeatherStation::orderBy('id', 'desc')
                ->limit($currentBatchSize)
                ->get()
                ->toArray();

            $stations = array_merge($stations, $insertedStations);
        }

        return $stations;
    });
}

function createBulkWeatherStationsWithSensors(int $stationCount, int $sensorsPerStation = 2): array
{
    $stations = createBulkWeatherStations($stationCount);

    TestWindvane::withoutEvents(function () use ($stations, $sensorsPerStation) {
        $windvaneData = [];
        $anemometerData = [];

        foreach ($stations as $station) {
            for ($i = 0; $i < $sensorsPerStation; $i++) {
                $windvaneData[] = [
                    'weather_station_id' => $station['id'],
                    'direction' => rand(0, 359) + (rand(0, 99) / 100),
                    'accuracy' => 90 + (rand(0, 99) / 10),
                    'calibration_date' => '2024-01-'.str_pad(rand(1, 28), 2, '0', STR_PAD_LEFT),
                    'created_at' => now(),
                    'updated_at' => now(),
                ];

                $anemometerData[] = [
                    'weather_station_id' => $station['id'],
                    'wind_speed' => rand(5, 25) + (rand(0, 99) / 100),
                    'max_speed' => rand(20, 40) + (rand(0, 99) / 100),
                    'sensor_type' => 'ultrasonic',
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }

            // Insert in batches of 100
            if (count($windvaneData) >= 100) {
                TestWindvane::insert($windvaneData);
                TestAnemometer::insert($anemometerData);
                $windvaneData = [];
                $anemometerData = [];
            }
        }

        // Insert remaining
        if (!empty($windvaneData)) {
            TestWindvane::insert($windvaneData);
            TestAnemometer::insert($anemometerData);
        }
    });

    return $stations;
}

function createComplexWeatherNetwork(int $stationCount, int $sensorsPerStation): array
{
    $stations = [];

    return TestWeatherStation::withoutEvents(function () use ($stationCount, $sensorsPerStation, &$stations) {
        for ($i = 0; $i < $stationCount; $i++) {
            $station = TestWeatherStation::create([
                'name' => 'Complex Station '.$i,
                'location' => 'Bayern',
                'latitude' => 48.1351 + ($i * 0.001),
                'longitude' => 11.5820 + ($i * 0.001),
                'status' => 'active',
                'is_operational' => true,
            ]);

            // Create interconnected sensors
            for ($j = 0; $j < $sensorsPerStation; $j++) {
                TestWindvane::create([
                    'weather_station_id' => $station->id,
                    'direction' => $j * 72, // Evenly distributed
                    'accuracy' => 95.0 + ($j * 0.5),
                    'calibration_date' => '2024-01-15',
                ]);

                TestAnemometer::create([
                    'weather_station_id' => $station->id,
                    'wind_speed' => 10 + ($j * 2),
                    'max_speed' => 25 + ($j * 3),
                    'sensor_type' => $j % 2 == 0 ? 'ultrasonic' : 'mechanical',
                ]);
            }

            $stations[] = $station;
        }

        return $stations;
    });
}

// ===== DATA MODIFICATION HELPERS =====

function updateRandomStations(int $count): void
{
    $stations = TestWeatherStation::inRandomOrder()->limit($count)->get();

    foreach ($stations as $station) {
        $station->name = $station->name.' Updated';
        $station->latitude = $station->latitude + 0.0001;
        $station->save();
    }
}

function updateStationsData(array $stations): void
{
    foreach ($stations as $station) {
        if (is_array($station)) {
            $model = TestWeatherStation::find($station['id']);
        } else {
            $model = $station;
        }

        if ($model) {
            $model->name = $model->name.' Modified';
            $model->status = $model->status === 'active' ? 'maintenance' : 'active';
            $model->save();
        }
    }
}

function deleteStations(array $stations): void
{
    $ids = array_map(function ($station) {
        return is_array($station) ? $station['id'] : $station->id;
    }, $stations);

    TestWeatherStation::whereIn('id', $ids)->delete();
}

// ===== PERFORMANCE MEASUREMENT HELPERS =====

function measureQueryPerformance(callable $operation): float
{
    $iterations = 5;
    $times = [];

    for ($i = 0; $i < $iterations; $i++) {
        $start = microtime(true);
        $operation();
        $times[] = microtime(true) - $start;
    }

    // Return average time
    return array_sum($times) / count($times);
}

function measureMemoryUsage(callable $operation): array
{
    $startMemory = memory_get_usage(true);
    $startPeak = memory_get_peak_usage(true);

    $operation();

    return [
        'used' => memory_get_usage(true) - $startMemory,
        'peak' => memory_get_peak_usage(true) - $startPeak,
        'final' => memory_get_usage(true),
    ];
}

// ===== DATABASE CONFIGURATION HELPERS =====

function isConfiguredForCrossDatabase(): bool
{
    $hashConnection = config('change-detection.database_connection', 'mysql');
    $defaultConnection = config('database.default');

    // Check if we have multiple database configurations
    $connections = config('database.connections');

    return count($connections) > 1 && $hashConnection !== $defaultConnection;
}

function configureCrossDatabaseHashes(): void
{
    // This would configure hashes to use a different database
    // For testing purposes, we'll simulate cross-database by using different schemas
    config(['change-detection.database_connection' => 'mysql_secondary']);
}

// ===== CLEANUP HELPERS =====

function cleanupAllTestData(): void
{
    // Clean up in correct order to avoid foreign key constraints
    DB::table('test_anemometers')->truncate();
    DB::table('test_windvanes')->truncate();
    DB::table('test_weather_stations')->truncate();

    Hash::where('hashable_type', 'test_weather_station')->forceDelete();
    Hash::where('hashable_type', 'test_windvane')->forceDelete();
    Hash::where('hashable_type', 'test_anemometer')->forceDelete();

    HashDependent::truncate();
    Publish::truncate();

    // Clear any cached data
    cache()->flush();

    // Force garbage collection
    gc_collect_cycles();
}

// ===== QUERY ANALYSIS HELPERS =====

function getChangeDetectionQuery(string $modelClass): string
{
    $model = new $modelClass;
    $table = $model->getTable();
    $primaryKey = $model->getKeyName();
    $morphClass = $model->getMorphClass();

    $hashesTable = config('change-detection.tables.hashes', 'hashes');

    return "
        SELECT m.{$primaryKey}
        FROM {$table} m
        LEFT JOIN {$hashesTable} h ON h.hashable_id = m.{$primaryKey}
            AND h.hashable_type = '{$morphClass}'
            AND h.deleted_at IS NULL
        WHERE h.id IS NULL OR h.attribute_hash != MD5(CONCAT_WS('|', m.name, m.location))
        LIMIT 100
    ";
}

// ===== LOGGING HELPERS =====

function logPerformanceMetrics(array $metrics): void
{
    Log::channel('testing')->info('Performance Metrics', $metrics);

    if (app()->environment('testing')) {
        dump('Performance Metrics:', $metrics);
    }
}

function logBatchSizeComparison(array $results): void
{
    $formatted = [];
    foreach ($results as $batchSize => $metrics) {
        $formatted[] = [
            'Batch Size' => $batchSize,
            'Time (s)' => round($metrics['time'], 3),
            'Memory (MB)' => round($metrics['memory'] / 1024 / 1024, 2),
            'Records/sec' => round($metrics['efficiency'], 1),
        ];
    }

    Log::channel('testing')->info('Batch Size Comparison', $formatted);

    if (app()->environment('testing')) {
        dump('Batch Size Comparison:', $formatted);
    }
}

function logMemoryPattern(array $snapshots): void
{
    $formatted = array_map(function ($memory, $index) {
        return [
            'Iteration' => $index + 1,
            'Memory (MB)' => round($memory / 1024 / 1024, 2),
        ];
    }, $snapshots, array_keys($snapshots));

    Log::channel('testing')->info('Memory Usage Pattern', $formatted);

    if (app()->environment('testing')) {
        dump('Memory Pattern:', $formatted);
    }
}

function logIndexPerformance(float $withoutIndex, float $withIndex, float $improvement): void
{
    $metrics = [
        'without_index_ms' => round($withoutIndex * 1000, 2),
        'with_index_ms' => round($withIndex * 1000, 2),
        'improvement_percent' => round($improvement, 1),
        'speedup' => round($withoutIndex / $withIndex, 2).'x',
    ];

    Log::channel('testing')->info('Index Performance Impact', $metrics);

    if (app()->environment('testing')) {
        dump('Index Performance:', $metrics);
    }
}

function logCrossDatabasePerformance(float $sameDb, float $crossDb, float $overhead): void
{
    $metrics = [
        'same_database_ms' => round($sameDb * 1000, 2),
        'cross_database_ms' => round($crossDb * 1000, 2),
        'overhead_percent' => round($overhead, 1),
        'latency_added_ms' => round(($crossDb - $sameDb) * 1000, 2),
    ];

    Log::channel('testing')->info('Cross-Database Performance', $metrics);

    if (app()->environment('testing')) {
        dump('Cross-Database Performance:', $metrics);
    }
}

function logConcurrentPerformance(array $jobs, float $totalTime): void
{
    $metrics = [
        'total_jobs' => count($jobs),
        'total_time_s' => round($totalTime, 2),
        'avg_time_per_job' => round($totalTime / count($jobs), 3),
        'jobs_per_second' => round(count($jobs) / $totalTime, 1),
    ];

    Log::channel('testing')->info('Concurrent Operations Performance', $metrics);

    if (app()->environment('testing')) {
        dump('Concurrent Performance:', $metrics);
    }
}

function logDependencyPerformance(float $time, float $memory, int $dependents): void
{
    $metrics = [
        'processing_time_s' => round($time, 2),
        'memory_used_mb' => round($memory / 1024 / 1024, 2),
        'dependents_count' => $dependents,
        'dependents_per_second' => round($dependents / $time, 1),
    ];

    Log::channel('testing')->info('Dependency Processing Performance', $metrics);

    if (app()->environment('testing')) {
        dump('Dependency Performance:', $metrics);
    }
}

function logBulkDeletePerformance(float $deleteTime, float $purgeTime): void
{
    $metrics = [
        'soft_delete_time_s' => round($deleteTime, 2),
        'purge_time_s' => round($purgeTime, 2),
        'total_time_s' => round($deleteTime + $purgeTime, 2),
        'purge_vs_delete_ratio' => round($purgeTime / $deleteTime, 2),
    ];

    Log::channel('testing')->info('Bulk Delete Performance', $metrics);

    if (app()->environment('testing')) {
        dump('Bulk Delete Performance:', $metrics);
    }
}

function logHashCalculationPerformance(float $single, float $bulk, float $speedup): void
{
    $metrics = [
        'single_calculation_s' => round($single, 3),
        'bulk_calculation_s' => round($bulk, 3),
        'speedup_factor' => round($speedup, 1).'x',
        'efficiency_gain' => round((1 - $bulk / $single) * 100, 1).'%',
    ];

    Log::channel('testing')->info('Hash Calculation Performance', $metrics);

    if (app()->environment('testing')) {
        dump('Hash Calculation Performance:', $metrics);
    }
}

function logSyncComparison(float $full, float $incremental, float $forced): void
{
    $metrics = [
        'initial_full_sync_s' => round($full, 2),
        'incremental_sync_s' => round($incremental, 2),
        'forced_full_sync_s' => round($forced, 2),
        'incremental_efficiency' => round((1 - $incremental / $full) * 100, 1).'%',
    ];

    Log::channel('testing')->info('Sync Strategy Comparison', $metrics);

    if (app()->environment('testing')) {
        dump('Sync Comparison:', $metrics);
    }
}

function logQueryPlan(array $explainResult): void
{
    $formatted = array_map(function ($row) {
        return [
            'table' => $row->table ?? 'N/A',
            'type' => $row->type ?? 'N/A',
            'key' => $row->key ?? 'None',
            'rows' => $row->rows ?? 0,
            'filtered' => $row->filtered ?? 0,
            'extra' => $row->Extra ?? '',
        ];
    }, $explainResult);

    Log::channel('testing')->info('Query Execution Plan', $formatted);

    if (app()->environment('testing')) {
        dump('Query Plan:', $formatted);
    }
}

function logMemoryLeakAnalysis(array $checkpoints, float $growth): void
{
    $metrics = [
        'iterations' => count($checkpoints),
        'start_memory_mb' => round($checkpoints[0] / 1024 / 1024, 2),
        'end_memory_mb' => round(end($checkpoints) / 1024 / 1024, 2),
        'total_growth_mb' => round($growth / 1024 / 1024, 2),
        'avg_growth_per_iteration_kb' => round($growth / count($checkpoints) / 1024, 2),
    ];

    Log::channel('testing')->info('Memory Leak Analysis', $metrics);

    if (app()->environment('testing')) {
        dump('Memory Leak Analysis:', $metrics);
    }
}

function logLockMetrics(array $metrics): void
{
    $formatted = [];
    foreach ($metrics as $batchSize => $data) {
        $formatted[] = [
            'batch_size' => $batchSize,
            'total_time_s' => round($data['total_time'], 3),
            'lock_waits' => $data['lock_waits'],
            'avg_lock_time_ms' => round($data['avg_lock_time'] * 1000, 2),
        ];
    }

    Log::channel('testing')->info('Database Lock Metrics', $formatted);

    if (app()->environment('testing')) {
        dump('Lock Metrics:', $formatted);
    }
}

function logCompositeHashPerformance(float $time, float $memory, int $totalRelations): void
{
    $metrics = [
        'processing_time_s' => round($time, 2),
        'memory_used_mb' => round($memory / 1024 / 1024, 2),
        'total_relations' => $totalRelations,
        'relations_per_second' => round($totalRelations / $time, 1),
        'memory_per_relation_kb' => round($memory / $totalRelations / 1024, 2),
    ];

    Log::channel('testing')->info('Composite Hash Performance', $metrics);

    if (app()->environment('testing')) {
        dump('Composite Hash Performance:', $metrics);
    }
}

function logConstrainedPerformance(float $time, float $peakMemory): void
{
    $metrics = [
        'processing_time_s' => round($time, 2),
        'peak_memory_mb' => round($peakMemory, 2),
        'completed' => true,
        'memory_efficient' => $peakMemory < 128,
    ];

    Log::channel('testing')->info('Resource-Constrained Performance', $metrics);

    if (app()->environment('testing')) {
        dump('Constrained Performance:', $metrics);
    }
}