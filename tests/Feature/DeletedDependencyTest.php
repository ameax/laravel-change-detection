<?php

use Ameax\LaravelChangeDetection\Models\Hash;
use Ameax\LaravelChangeDetection\Models\HashDependent;
use Ameax\LaravelChangeDetection\Services\DependencyHashCalculator;
use Ameax\LaravelChangeDetection\Tests\Models\TestAnemometer;
use Ameax\LaravelChangeDetection\Tests\Models\TestWeatherStation;
use Ameax\LaravelChangeDetection\Tests\Models\TestWindvane;
use Illuminate\Database\Eloquent\Relations\Relation;

beforeEach(function () {
    Relation::morphMap([
        'test_weather_station' => TestWeatherStation::class,
        'test_anemometer' => TestAnemometer::class,
        'test_windvane' => TestWindvane::class,
    ]);
});

it('excludes soft-deleted dependencies from composite hash calculation', function () {
    // Create station with dependencies
    $station = TestWeatherStation::create([
        'name' => 'Delete Test Station',
        'location' => 'Bayern',
        'latitude' => 48.1351,
        'longitude' => 11.5820,
        'status' => 'active',
        'is_operational' => true,
    ]);

    $anemometer = TestAnemometer::create([
        'weather_station_id' => $station->id,
        'wind_speed' => 10.0,
        'max_speed' => 20.0,
        'sensor_type' => 'test',
    ]);

    $windvane = TestWindvane::create([
        'weather_station_id' => $station->id,
        'direction' => 180.0,
        'accuracy' => 95.0,
        'calibration_date' => now(),
    ]);

    // Create publisher and sync
    createWeatherStationPublisher();
    runWeatherStationSync();

    // Get initial hashes
    $stationHash = Hash::where('hashable_type', 'test_weather_station')
        ->where('hashable_id', $station->id)
        ->first();

    dump('Initial state:');
    dump('  Station attribute hash: ' . $stationHash->attribute_hash);
    dump('  Station composite hash: ' . $stationHash->composite_hash);
    dump('  Are they same: ' . ($stationHash->attribute_hash === $stationHash->composite_hash ? 'YES' : 'NO'));

    // Check dependencies
    $deps = HashDependent::where('dependent_model_type', 'test_weather_station')
        ->where('dependent_model_id', $station->id)
        ->get();
    dump('  Dependencies count: ' . $deps->count());

    // Now delete one dependency
    dump("\nDeleting anemometer...");
    $anemometer->delete();
    runWeatherStationSync();

    // Check if anemometer hash is soft-deleted
    $anemHashAfterDelete = Hash::where('hashable_type', 'test_anemometer')
        ->where('hashable_id', $anemometer->id)
        ->first();
    dump('Anemometer hash deleted_at: ' . ($anemHashAfterDelete->deleted_at ? 'SOFT DELETED' : 'NULL'));

    // Check station composite hash after first delete
    $stationHashAfter1 = Hash::where('hashable_type', 'test_weather_station')
        ->where('hashable_id', $station->id)
        ->first();

    dump('After deleting anemometer:');
    dump('  Station composite hash: ' . $stationHashAfter1->composite_hash);
    dump('  Changed from initial: ' . ($stationHashAfter1->composite_hash !== $stationHash->composite_hash ? 'YES' : 'NO'));

    // Delete the second dependency
    dump("\nDeleting windvane...");
    $windvane->delete();
    runWeatherStationSync();

    // Check station composite hash after all dependencies deleted
    $stationHashFinal = Hash::where('hashable_type', 'test_weather_station')
        ->where('hashable_id', $station->id)
        ->first();

    dump('After deleting all dependencies:');
    dump('  Station attribute hash: ' . $stationHashFinal->attribute_hash);
    dump('  Station composite hash: ' . $stationHashFinal->composite_hash);
    dump('  Should be same (no deps): ' . ($stationHashFinal->attribute_hash === $stationHashFinal->composite_hash ? 'YES' : 'NO'));

    // Manually calculate what the dependency hash should be
    $calculator = app(DependencyHashCalculator::class);
    $calculatedDepsHash = $calculator->calculate($station);
    dump('  Manually calculated deps hash: ' . ($calculatedDepsHash ?? 'NULL'));

    // Check if hash_dependents still exist
    $remainingDeps = HashDependent::where('dependent_model_type', 'test_weather_station')
        ->where('dependent_model_id', $station->id)
        ->get();
    dump('  Remaining hash_dependents: ' . $remainingDeps->count());

    // The composite hash should equal attribute hash when all dependencies are deleted
    expect($stationHashFinal->composite_hash)->toBe($stationHashFinal->attribute_hash);
});