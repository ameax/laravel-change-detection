<?php

use Ameax\LaravelChangeDetection\Models\Hash;
use Ameax\LaravelChangeDetection\Models\HashDependent;
use Ameax\LaravelChangeDetection\Services\BulkHashProcessor;
use Ameax\LaravelChangeDetection\Tests\Models\TestAnemometer;
use Ameax\LaravelChangeDetection\Tests\Models\TestWeatherStation;
use Illuminate\Database\Eloquent\Relations\Relation;

beforeEach(function () {
    Relation::morphMap([
        'test_weather_station' => TestWeatherStation::class,
        'test_anemometer' => TestAnemometer::class,
        'test_windvane' => TestWindvane::class,
    ]);
});

it('manually builds dependencies for weather station', function () {
    // Create station and anemometer
    $station = TestWeatherStation::create([
        'name' => 'Manual Test Station',
        'location' => 'Bayern',
        'latitude' => 48.1351,
        'longitude' => 11.5820,
        'status' => 'active',
        'is_operational' => true,
    ]);

    $anemometer = TestAnemometer::create([
        'weather_station_id' => $station->id,
        'wind_speed' => 12.5,
        'max_speed' => 25.0,
        'sensor_type' => 'ultrasonic',
    ]);

    // Create hashes for both manually
    Hash::create([
        'hashable_type' => 'test_anemometer',
        'hashable_id' => $anemometer->id,
        'attribute_hash' => 'anem_hash_123',
        'composite_hash' => 'anem_hash_123',
        'has_dependencies_built' => true,
    ]);

    Hash::create([
        'hashable_type' => 'test_weather_station',
        'hashable_id' => $station->id,
        'attribute_hash' => 'station_hash_456',
        'composite_hash' => 'station_hash_456', // Will be updated
        'has_dependencies_built' => false,
    ]);

    // dump('Initial state:');
    // dump('Station hash exists: yes');
    // dump('Anemometer hash exists: yes');
    // dump('Hash dependents count: '.HashDependent::count());

    // Now manually call buildPendingDependencies
    $processor = app(BulkHashProcessor::class);
    $result = $processor->buildPendingDependencies(TestWeatherStation::class);
    // dump('Built dependencies for '.$result.' models');

    // Check hash_dependents
    $dependents = HashDependent::all();
    // dump('Hash dependents after build: '.$dependents->count());
    foreach ($dependents as $dep) {
        // dump('Dependent record:');
        // dump('  hash_id: '.$dep->hash_id);
        // dump('  dependent_model_type: '.$dep->dependent_model_type);
        // dump('  dependent_model_id: '.$dep->dependent_model_id);
        // dump('  relation_name: '.$dep->relation_name);

        // Look up what the hash_id points to
        $hash = Hash::find($dep->hash_id);
        if ($hash) {
            // dump('  hash points to: '.$hash->hashable_type.'#'.$hash->hashable_id);
        }
    }

    // Check if has_dependencies_built was updated
    $stationHashAfter = Hash::where('hashable_type', 'test_weather_station')
        ->where('hashable_id', $station->id)
        ->first();
    // dump('Station has_dependencies_built after: '.($stationHashAfter->has_dependencies_built ? 'yes' : 'no'));

    expect($dependents->count())->toBeGreaterThan(0);
});
