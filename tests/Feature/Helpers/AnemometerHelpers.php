<?php

use Ameax\LaravelChangeDetection\Models\Hash;
use Ameax\LaravelChangeDetection\Models\Publisher;
use Ameax\LaravelChangeDetection\Tests\Models\TestAnemometer;
use Ameax\LaravelChangeDetection\Tests\Models\TestWeatherStation;

// ===== ANEMOMETER-SPECIFIC SYNC FUNCTIONS =====

function runAnemometerSync(array $options = []): void
{
    runSyncForModel(TestAnemometer::class, $options);
}

// ===== ANEMOMETER-SPECIFIC HASH FUNCTIONS =====

function getAnemometerHash(int $anemometerId): ?Hash
{
    return getHashForModel('test_anemometer', $anemometerId);
}

function expectAnemometerHashExists(int $anemometerId): void
{
    expectHashExists('test_anemometer', $anemometerId);
}

function expectAnemometerHashNotExists(int $anemometerId): void
{
    expectHashNotExists('test_anemometer', $anemometerId);
}

function expectAnemometerHashActive(int $anemometerId): void
{
    expectHashActive('test_anemometer', $anemometerId);
}

function expectAnemometerHashSoftDeleted(int $anemometerId): void
{
    expectHashSoftDeleted('test_anemometer', $anemometerId);
}

function expectActiveAnemometerCount(int $count): void
{
    expectActiveHashCountForType('test_anemometer', $count);
}

function expectTotalAnemometerCount(int $count): void
{
    expectTotalHashCountForType('test_anemometer', $count);
}

// ===== ANEMOMETER-SPECIFIC PUBLISHER FUNCTIONS =====

function createAnemometerPublisher(array $overrides = []): Publisher
{
    return createPublisherForModel('test_anemometer', 'Test Anemometer Publisher', $overrides);
}

// ===== ANEMOMETER DATA MANIPULATION =====

function updateAnemometerWindSpeed(int $id, float $speed): TestAnemometer
{
    $anemometer = TestAnemometer::find($id);
    $anemometer->wind_speed = $speed;
    $anemometer->save();

    return $anemometer;
}

function updateAnemometerMaxSpeed(int $id, float $maxSpeed): TestAnemometer
{
    $anemometer = TestAnemometer::find($id);
    $anemometer->max_speed = $maxSpeed;
    $anemometer->save();

    return $anemometer;
}

function updateAnemometerSensorType(int $id, string $sensorType): TestAnemometer
{
    $anemometer = TestAnemometer::find($id);
    $anemometer->sensor_type = $sensorType;
    $anemometer->save();

    return $anemometer;
}

function updateAnemometerAttribute(int $id, string $attribute, $value): TestAnemometer
{
    $anemometer = TestAnemometer::find($id);
    $anemometer->$attribute = $value;
    $anemometer->save();

    return $anemometer;
}

// ===== ANEMOMETER TEST DATA SETUP =====

function createAnemometer(array $attributes = []): TestAnemometer
{
    $defaults = [
        'weather_station_id' => 1,
        'wind_speed' => 12.5,
        'max_speed' => 25.0,
        'sensor_type' => 'ultrasonic',
    ];

    return TestAnemometer::create(array_merge($defaults, $attributes));
}

function createAnemometerWithoutEvents(array $attributes = []): TestAnemometer
{
    return TestAnemometer::withoutEvents(function () use ($attributes) {
        return createAnemometer($attributes);
    });
}

function createHighSpeedAnemometer(int $stationId, float $maxSpeed = 35.0): TestAnemometer
{
    return createAnemometerWithoutEvents([
        'weather_station_id' => $stationId,
        'wind_speed' => $maxSpeed * 0.6, // Current at 60% of max
        'max_speed' => $maxSpeed,
        'sensor_type' => 'ultrasonic',
    ]);
}

function createLowSpeedAnemometer(int $stationId, float $maxSpeed = 15.0): TestAnemometer
{
    return createAnemometerWithoutEvents([
        'weather_station_id' => $stationId,
        'wind_speed' => $maxSpeed * 0.4, // Current at 40% of max
        'max_speed' => $maxSpeed,
        'sensor_type' => 'mechanical',
    ]);
}

function createMechanicalAnemometer(int $stationId): TestAnemometer
{
    return createAnemometerWithoutEvents([
        'weather_station_id' => $stationId,
        'wind_speed' => 8.0,
        'max_speed' => 20.0,
        'sensor_type' => 'mechanical',
    ]);
}

function createUltrasonicAnemometer(int $stationId): TestAnemometer
{
    return createAnemometerWithoutEvents([
        'weather_station_id' => $stationId,
        'wind_speed' => 15.0,
        'max_speed' => 30.0,
        'sensor_type' => 'ultrasonic',
    ]);
}

function setupAnemometerSet(int $stationId, int $count = 3): array
{
    $anemometers = [];
    $types = ['mechanical', 'ultrasonic', 'cup', 'hot-wire'];

    for ($i = 0; $i < $count; $i++) {
        $anemometers[] = createAnemometerWithoutEvents([
            'weather_station_id' => $stationId,
            'wind_speed' => 5.0 + ($i * 5), // 5, 10, 15...
            'max_speed' => 20.0 + ($i * 10), // 20, 30, 40...
            'sensor_type' => $types[$i % count($types)],
        ]);
    }

    return $anemometers;
}

// ===== ANEMOMETER-SPECIFIC TEST SCENARIOS =====

function simulateWindSpeedIncrease(int $anemometerId, float $startSpeed = 0.0, float $endSpeed = 30.0, int $steps = 5): array
{
    $speeds = [];
    $increment = ($endSpeed - $startSpeed) / $steps;

    for ($i = 0; $i <= $steps; $i++) {
        $speed = $startSpeed + ($i * $increment);
        updateAnemometerWindSpeed($anemometerId, $speed);
        runAnemometerSync();
        $speeds[$speed] = getAnemometerHash($anemometerId)?->attribute_hash;
    }

    return array_filter($speeds);
}

function simulateStormConditions(int $anemometerId): array
{
    $conditions = [];

    // Calm
    updateAnemometerWindSpeed($anemometerId, 2.0);
    runAnemometerSync();
    $conditions['calm'] = getAnemometerHash($anemometerId);

    // Breeze
    updateAnemometerWindSpeed($anemometerId, 8.0);
    runAnemometerSync();
    $conditions['breeze'] = getAnemometerHash($anemometerId);

    // Strong wind
    updateAnemometerWindSpeed($anemometerId, 20.0);
    runAnemometerSync();
    $conditions['strong_wind'] = getAnemometerHash($anemometerId);

    // Storm
    updateAnemometerWindSpeed($anemometerId, 35.0);
    updateAnemometerMaxSpeed($anemometerId, 40.0); // Update max if needed
    runAnemometerSync();
    $conditions['storm'] = getAnemometerHash($anemometerId);

    // Hurricane
    updateAnemometerWindSpeed($anemometerId, 50.0);
    updateAnemometerMaxSpeed($anemometerId, 55.0);
    runAnemometerSync();
    $conditions['hurricane'] = getAnemometerHash($anemometerId);

    return $conditions;
}

function simulateWindGust(int $anemometerId, float $baseSpeed = 10.0, float $gustSpeed = 25.0): array
{
    $measurements = [];

    // Base wind
    updateAnemometerWindSpeed($anemometerId, $baseSpeed);
    runAnemometerSync();
    $measurements['base'] = getAnemometerHash($anemometerId);

    // Gust starts
    updateAnemometerWindSpeed($anemometerId, $gustSpeed);
    runAnemometerSync();
    $measurements['gust_peak'] = getAnemometerHash($anemometerId);

    // Gust subsides
    updateAnemometerWindSpeed($anemometerId, $baseSpeed * 1.2);
    runAnemometerSync();
    $measurements['gust_subsiding'] = getAnemometerHash($anemometerId);

    // Return to base
    updateAnemometerWindSpeed($anemometerId, $baseSpeed);
    runAnemometerSync();
    $measurements['base_return'] = getAnemometerHash($anemometerId);

    return $measurements;
}

function testSensorTypeChange(int $anemometerId): array
{
    $hashes = [];
    $sensorTypes = ['mechanical', 'ultrasonic', 'cup', 'hot-wire', 'laser'];

    foreach ($sensorTypes as $type) {
        updateAnemometerSensorType($anemometerId, $type);
        runAnemometerSync();
        $hashes[$type] = getAnemometerHash($anemometerId)?->attribute_hash;
    }

    return array_filter($hashes);
}

// ===== ANEMOMETER RELATIONSHIP HELPERS =====

function attachAnemometerToStation(TestAnemometer $anemometer, TestWeatherStation $station): TestAnemometer
{
    $anemometer->weather_station_id = $station->id;
    $anemometer->save();

    return $anemometer->fresh();
}

function detachAnemometerFromStation(TestAnemometer $anemometer): TestAnemometer
{
    $anemometer->weather_station_id = null;
    $anemometer->save();

    return $anemometer->fresh();
}

// ===== ANEMOMETER ASSERTION HELPERS =====

function assertAnemometerHasHash(TestAnemometer $anemometer): Hash
{
    $hash = Hash::where('hashable_type', 'test_anemometer')
        ->where('hashable_id', $anemometer->id)
        ->first();

    expect($hash)->not->toBeNull();
    expect($hash->attribute_hash)->not->toBeNull();

    return $hash;
}

function assertAnemometersHaveHashes(array $anemometers): void
{
    foreach ($anemometers as $anemometer) {
        assertAnemometerHasHash($anemometer);
    }
}

function assertAnemometerInQualifyingRange(TestAnemometer $anemometer): void
{
    expect($anemometer->max_speed)->toBeGreaterThan(20.0, 'Anemometer should have max_speed > 20 to qualify for scope');
}

function assertAnemometerOutOfQualifyingRange(TestAnemometer $anemometer): void
{
    expect($anemometer->max_speed)->toBeLessThanOrEqual(20.0, 'Anemometer should have max_speed <= 20 to be out of scope');
}