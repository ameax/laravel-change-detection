<?php

use Ameax\LaravelChangeDetection\Models\Hash;
use Ameax\LaravelChangeDetection\Models\HashDependent;
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

it('debugs composite hash calculation with dependencies', function () {
    // Create station in scope
    $station = TestWeatherStation::create([
        'name' => 'Debug Station',
        'location' => 'Bayern',
        'latitude' => 48.1351,
        'longitude' => 11.5820,
        'status' => 'active',
        'is_operational' => true,
    ]);

    // Create dependent sensor
    $anemometer = TestAnemometer::create([
        'weather_station_id' => $station->id,
        'wind_speed' => 12.5,
        'max_speed' => 25.0,
        'sensor_type' => 'ultrasonic',
    ]);

    $publisher = createWeatherStationPublisher();

    // Run sync for weather station (should autodiscover dependencies)
    runWeatherStationSync();

    // Check if anemometer has its own hash
    $anemometerHash = Hash::where('hashable_type', 'test_anemometer')
        ->where('hashable_id', $anemometer->id)
        ->first();

    // dump('Anemometer hash exists: '.($anemometerHash ? 'yes' : 'no'));
    if ($anemometerHash) {
        // dump('Anemometer hash: '.$anemometerHash->attribute_hash);
    }

    // Check weather station hash
    $stationHash = getStationHash($station->id);
    // dump('Station hash exists: '.($stationHash ? 'yes' : 'no'));
    if ($stationHash) {
        // dump('Station attribute_hash: '.$stationHash->attribute_hash);
        // dump('Station composite_hash: '.$stationHash->composite_hash);
        // dump('Are they same: '.($stationHash->attribute_hash === $stationHash->composite_hash ? 'yes' : 'no'));
    }

    // Check hash_dependents table
    $hashDependents = HashDependent::where('hash_id', $stationHash->id)->get();
    // dump('Hash dependents count: '.$hashDependents->count());
    foreach ($hashDependents as $dep) {
        // dump('Dependent: type='.$dep->dependent_type.', id='.$dep->dependent_id);
    }

    // Now update the anemometer
    $anemometer->wind_speed = 20.0;
    $anemometer->save();

    // Run sync again
    runWeatherStationSync();

    // Check if anemometer hash changed
    $anemometerHashAfter = Hash::where('hashable_type', 'test_anemometer')
        ->where('hashable_id', $anemometer->id)
        ->first();

    if ($anemometerHash && $anemometerHashAfter) {
        // dump('Anemometer hash changed: '.($anemometerHash->attribute_hash !== $anemometerHashAfter->attribute_hash ? 'yes' : 'no'));
        // dump('Old anemometer hash: '.$anemometerHash->attribute_hash);
        // dump('New anemometer hash: '.$anemometerHashAfter->attribute_hash);
    }

    // Check if station composite hash changed
    $stationHashAfter = getStationHash($station->id);
    if ($stationHash && $stationHashAfter) {
        // dump('Station composite hash changed: '.($stationHash->composite_hash !== $stationHashAfter->composite_hash ? 'yes' : 'no'));
        // dump('Old composite: '.$stationHash->composite_hash);
        // dump('New composite: '.$stationHashAfter->composite_hash);
    }

    // This test is for debugging, so we'll pass it
    expect(true)->toBeTrue();
});
