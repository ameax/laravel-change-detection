<?php

use Ameax\LaravelChangeDetection\Models\Hash;
use Ameax\LaravelChangeDetection\Models\Publisher;
use Ameax\LaravelChangeDetection\Publishers\LogPublisher;
use Ameax\LaravelChangeDetection\Tests\Models\TestWeatherStation;
use Ameax\LaravelChangeDetection\Tests\Models\TestWindvane;
use Ameax\LaravelChangeDetection\Tests\Models\TestAnemometer;
use Illuminate\Database\Eloquent\Relations\Relation;

beforeEach(function () {
    Relation::morphMap(['test_weather_station' => TestWeatherStation::class]);
});

it('demonstrates complex hash system behavior and limitations', function () {
    // Create publisher to track hash changes
    $publisher = Publisher::create([
        'name' => 'Weather Hash Tracker',
        'model_type' => 'test_weather_station',
        'publisher_class' => LogPublisher::class,
        'status' => 'active',
        'config' => ['log_level' => 'info'],
    ]);

    // Create station with multiple sensors for complex composite dependencies
    $station = TestWeatherStation::create([
        'name' => 'Complex Hash Station',
        'location' => 'Test Lab',
        'latitude' => 40.7128,
        'longitude' => -74.0060,
        'status' => 'active',
        'is_operational' => true,
    ]);

    $windvane = TestWindvane::create([
        'weather_station_id' => $station->id,
        'direction' => 90.0,
        'accuracy' => 95.0,
        'calibration_date' => '2024-01-01',
    ]);

    $anemometer = TestAnemometer::create([
        'weather_station_id' => $station->id,
        'wind_speed' => 10.0,
        'max_speed' => 20.0,
        'sensor_type' => 'ultrasonic',
    ]);

    // Initial sync - establish baseline hashes
    runSyncForModel(TestWeatherStation::class);

    $initialHash = Hash::where('hashable_type', 'test_weather_station')
        ->where('hashable_id', $station->id)->first();

    expect($initialHash)->not->toBeNull();
    expect($initialHash->composite_hash)->not->toBeNull();

    $originalAttributeHash = $initialHash->attribute_hash;
    $originalCompositeHash = $initialHash->composite_hash;

    // Test 1: Station attribute changes should update both attribute_hash and composite_hash
    $station->update(['name' => 'Renamed Station']);
    runSyncForModel(TestWeatherStation::class);

    $attributeChangedHash = Hash::where('hashable_type', 'test_weather_station')
        ->where('hashable_id', $station->id)->first();

    expect($attributeChangedHash->attribute_hash)->not->toBe($originalAttributeHash);
    expect($attributeChangedHash->composite_hash)->not->toBe($originalCompositeHash); // Changes when parent updates

    // Test 2: Verify composite dependencies are defined correctly
    expect($station->getHashCompositeDependencies())->toBe(['windvanes', 'anemometers']);
    expect($station->windvanes)->toHaveCount(1);
    expect($station->anemometers)->toHaveCount(1);

    // Test 3: Multiple station changes show hash evolution
    $station->update(['location' => 'Updated Location']);
    runSyncForModel(TestWeatherStation::class);

    $secondHash = Hash::where('hashable_type', 'test_weather_station')
        ->where('hashable_id', $station->id)->first();

    expect($secondHash->composite_hash)->not->toBe($attributeChangedHash->composite_hash);

    $station->update(['latitude' => 41.0000]);
    runSyncForModel(TestWeatherStation::class);

    $thirdHash = Hash::where('hashable_type', 'test_weather_station')
        ->where('hashable_id', $station->id)->first();

    expect($thirdHash->composite_hash)->not->toBe($secondHash->composite_hash);

    // Test 4: Business scope changes should soft-delete while preserving hash history
    $station->update(['is_operational' => false]);
    runSyncForModel(TestWeatherStation::class);

    $scopeChangedHash = Hash::where('hashable_type', 'test_weather_station')
        ->where('hashable_id', $station->id)->first();

    expect($scopeChangedHash->deleted_at)->not->toBeNull(); // Soft deleted
    expect($scopeChangedHash->composite_hash)->not->toBeNull(); // Hash preserved for audit

    // Test 5: Verify hash evolution through multiple states
    $allHashes = [
        $originalCompositeHash,
        $attributeChangedHash->composite_hash,
        $secondHash->composite_hash,
        $thirdHash->composite_hash
    ];
    expect(count(array_unique($allHashes)))->toBe(4); // All different states

    // Test 6: Publisher tracking
    $publishCount = \Ameax\LaravelChangeDetection\Models\Publish::where('publisher_id', $publisher->id)->count();
    expect($publishCount)->toBe(1); // One station being tracked

    // Test 7: Final state verification
    expect(Hash::where('hashable_type', 'test_weather_station')->whereNull('deleted_at')->count())->toBe(0);
});
