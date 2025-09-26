<?php

use Ameax\LaravelChangeDetection\Models\Hash;
use Ameax\LaravelChangeDetection\Models\Publisher;
use Ameax\LaravelChangeDetection\Tests\Models\TestAnemometer;
use Ameax\LaravelChangeDetection\Tests\Models\TestWeatherStation;
use Ameax\LaravelChangeDetection\Tests\Models\TestWindvane;

// ===== WEATHER STATION-SPECIFIC SYNC FUNCTIONS =====

function runWeatherStationSync(array $options = []): void
{
    // Use autodiscovery to sync all models related to the weather station publisher
    // The sync command will:
    // 1. Find the WeatherStation publisher
    // 2. Autodiscover dependent models from getHashCompositeDependencies()
    // 3. Sync them in the correct order (dependencies first, then main model)
    runSyncAutoDiscover($options);
}

function runWeatherStationSyncOnly(array $options = []): void
{
    // Only sync the weather station model (for testing specific scenarios)
    runSyncForModel(TestWeatherStation::class, $options);
}

function runWindvaneSync(array $options = []): void
{
    runSyncForModel(TestWindvane::class, $options);
}

function runAnemometerSync(array $options = []): void
{
    runSyncForModel(TestAnemometer::class, $options);
}

// ===== WEATHER STATION-SPECIFIC HASH FUNCTIONS =====

function expectOperationalStationCount(int $count): void
{
    expectActiveHashCountForType('test_weather_station', $count);
}

function expectTotalStationCount(int $count): void
{
    expectTotalHashCountForType('test_weather_station', $count);
}

function getStationHash(int $stationId): ?Hash
{
    return getHashForModel('test_weather_station', $stationId);
}

function expectStationHashExists(int $stationId): void
{
    expectHashExists('test_weather_station', $stationId);
}

function expectStationHashNotExists(int $stationId): void
{
    expectHashNotExists('test_weather_station', $stationId);
}

function expectStationHashActive(int $stationId): void
{
    expectHashActive('test_weather_station', $stationId);
}

function expectStationHashSoftDeleted(int $stationId): void
{
    expectHashSoftDeleted('test_weather_station', $stationId);
}

// ===== WEATHER STATION-SPECIFIC PUBLISHER FUNCTIONS =====

function createWeatherStationPublisher(array $overrides = []): Publisher
{
    return createPublisherForModel('test_weather_station', 'Test Weather Station Publisher', $overrides);
}

function expectStationPublishCount(Publisher $publisher, int $count): void
{
    expectPublishCount($publisher, $count);
}

// ===== WEATHER STATION DATA MANIPULATION =====

function updateStationOperationalStatus(int $id, bool $isOperational): TestWeatherStation
{
    $station = TestWeatherStation::find($id);
    $station->is_operational = $isOperational;
    $station->save();

    return $station;
}

function updateStationStatus(int $id, string $status): TestWeatherStation
{
    $station = TestWeatherStation::find($id);
    $station->status = $status;
    $station->save();

    return $station;
}

function updateStationAttribute(int $id, string $attribute, $value): TestWeatherStation
{
    $station = TestWeatherStation::find($id);
    $station->$attribute = $value;
    $station->save();

    return $station;
}

function makeStationOperational(int $id): TestWeatherStation
{
    return updateStationOperationalStatus($id, true);
}

function makeStationNonOperational(int $id): TestWeatherStation
{
    return updateStationOperationalStatus($id, false);
}

function activateStation(int $id): TestWeatherStation
{
    return updateStationStatus($id, 'active');
}

function deactivateStation(int $id): TestWeatherStation
{
    return updateStationStatus($id, 'inactive');
}

// ===== SENSOR DATA MANIPULATION =====

function updateWindDirection(int $windvaneId, float $newDirection): TestWindvane
{
    $windvane = TestWindvane::find($windvaneId);
    $windvane->direction = $newDirection;
    $windvane->save();

    return $windvane;
}

function updateWindSpeed(int $anemomenterId, float $newSpeed): TestAnemometer
{
    $anemometer = TestAnemometer::find($anemomenterId);
    $anemometer->wind_speed = $newSpeed;
    $anemometer->save();

    return $anemometer;
}

function updateWindvaneAccuracy(int $windvaneId, float $accuracy): TestWindvane
{
    $windvane = TestWindvane::find($windvaneId);
    $windvane->accuracy = $accuracy;
    $windvane->save();

    return $windvane;
}

function updateAnemometerMaxSpeed(int $anemomenterId, float $maxSpeed): TestAnemometer
{
    $anemometer = TestAnemometer::find($anemomenterId);
    $anemometer->max_speed = $maxSpeed;
    $anemometer->save();

    return $anemometer;
}

// ===== WEATHER STATION TEST DATA SETUP =====

function setupStandardWeatherStations(): array
{
    return TestWeatherStation::withoutEvents(function () {
        // Create operational station with both sensors
        $operationalStation = TestWeatherStation::create([
            'name' => 'Bayern 1',
            'location' => 'Bayern',
            'latitude' => 40.7128,
            'longitude' => -74.0060,
            'status' => 'active',
            'is_operational' => true,
        ]);

        // Create non-operational station
        $nonOperationalStation = TestWeatherStation::create([
            'name' => 'Maintenance Station',
            'location' => 'Industrial Zone',
            'latitude' => 40.7500,
            'longitude' => -73.9857,
            'status' => 'active',
            'is_operational' => false,
        ]);

        // Create inactive status station
        $inactiveStation = TestWeatherStation::create([
            'name' => 'Decommissioned Station',
            'location' => 'Old Airport',
            'latitude' => 40.6892,
            'longitude' => -74.1745,
            'status' => 'inactive',
            'is_operational' => true,
        ]);

        // Create operational station without sensors (for incomplete setup tests)
        $incompleteStation = TestWeatherStation::create([
            'name' => 'Bayern 2',
            'location' => 'Bayern',
            'latitude' => 40.6782,
            'longitude' => -73.9442,
            'status' => 'active',
            'is_operational' => true,
        ]);

        // Add sensors to operational station
        $windvane1 = TestWindvane::create([
            'weather_station_id' => $operationalStation->id,
            'direction' => 180.50,
            'accuracy' => 95.5,
            'calibration_date' => '2024-01-15',
        ]);

        $anemometer1 = TestAnemometer::create([
            'weather_station_id' => $operationalStation->id,
            'wind_speed' => 12.5,
            'max_speed' => 25.3,
            'sensor_type' => 'ultrasonic',
        ]);

        // Add sensors to non-operational station
        TestWindvane::create([
            'weather_station_id' => $nonOperationalStation->id,
            'direction' => 45.0,
            'accuracy' => 88.0,
            'calibration_date' => '2023-12-01',
        ]);

        TestAnemometer::create([
            'weather_station_id' => $nonOperationalStation->id,
            'wind_speed' => 8.2,
            'max_speed' => 18.7,
            'sensor_type' => 'mechanical',
        ]);

        // Add only windvane to incomplete station (missing anemometer)
        TestWindvane::create([
            'weather_station_id' => $incompleteStation->id,
            'direction' => 270.0,
            'accuracy' => 92.0,
            'calibration_date' => '2024-02-01',
        ]);

        return [
            'operational' => $operationalStation,
            'non_operational' => $nonOperationalStation,
            'inactive' => $inactiveStation,
            'incomplete' => $incompleteStation,
            'windvane1' => $windvane1,
            'anemometer1' => $anemometer1,
        ];
    });
}

function setupHighWindScenario(): array
{
    return TestWeatherStation::withoutEvents(function () {
        $station = TestWeatherStation::create([
            'name' => 'Storm Station',
            'location' => 'Coastal Area',
            'latitude' => 40.7831,
            'longitude' => -73.9712,
            'status' => 'active',
            'is_operational' => true,
        ]);

        $windvane = TestWindvane::create([
            'weather_station_id' => $station->id,
            'direction' => 90.0,
            'accuracy' => 97.0,
            'calibration_date' => '2024-01-01',
        ]);

        $anemometer = TestAnemometer::create([
            'weather_station_id' => $station->id,
            'wind_speed' => 15.8,
            'max_speed' => 32.5,
            'sensor_type' => 'ultrasonic',
        ]);

        return [
            'station' => $station,
            'windvane' => $windvane,
            'anemometer' => $anemometer,
        ];
    });
}

function setupCalibrationTestScenario(): array
{
    return TestWeatherStation::withoutEvents(function () {
        $station = TestWeatherStation::create([
            'name' => 'Calibration Test Station',
            'location' => 'Research Facility',
            'latitude' => 40.8176,
            'longitude' => -73.9782,
            'status' => 'active',
            'is_operational' => true,
        ]);

        $accurateWindvane = TestWindvane::create([
            'weather_station_id' => $station->id,
            'direction' => 135.0,
            'accuracy' => 95.0,
            'calibration_date' => '2024-01-15',
        ]);

        $inaccurateWindvane = TestWindvane::create([
            'weather_station_id' => $station->id,
            'direction' => 225.0,
            'accuracy' => 85.0,
            'calibration_date' => '2023-06-01',
        ]);

        $anemometer = TestAnemometer::create([
            'weather_station_id' => $station->id,
            'wind_speed' => 7.2,
            'max_speed' => 14.8,
            'sensor_type' => 'mechanical',
        ]);

        return [
            'station' => $station,
            'accurate_windvane' => $accurateWindvane,
            'inaccurate_windvane' => $inaccurateWindvane,
            'anemometer' => $anemometer,
        ];
    });
}

function simulateStormConditions(int $stationId): array
{
    $station = TestWeatherStation::find($stationId);
    $windvane = $station->windvanes->first();
    $anemometer = $station->anemometers->first();

    $conditions = [];

    // Initial calm conditions
    updateWindDirection($windvane->id, 180.0); // South
    updateWindSpeed($anemometer->id, 5.0);     // Light breeze
    runWeatherStationSync();
    $conditions['calm'] = getStationHash($stationId);

    // Storm approaches
    updateWindDirection($windvane->id, 45.0);  // Northeast shift
    updateWindSpeed($anemometer->id, 18.5);    // Strong winds
    runWeatherStationSync();
    $conditions['storm'] = getStationHash($stationId);

    // Storm peaks
    updateWindDirection($windvane->id, 90.0);  // East
    updateWindSpeed($anemometer->id, 28.3);    // Gale force
    runWeatherStationSync();
    $conditions['peak'] = getStationHash($stationId);

    // Storm subsides
    updateWindDirection($windvane->id, 135.0); // Southeast
    updateWindSpeed($anemometer->id, 12.0);    // Moderate
    runWeatherStationSync();
    $conditions['subsiding'] = getStationHash($stationId);

    return $conditions;
}

function simulateMaintenanceCycle(int $stationId): array
{
    $lifecycle = [];

    // Normal operation
    makeStationOperational($stationId);
    activateStation($stationId);
    runWeatherStationSync();
    $lifecycle['operational'] = getStationHash($stationId);

    // Maintenance mode
    makeStationNonOperational($stationId);
    runWeatherStationSync();
    $lifecycle['maintenance'] = getStationHash($stationId);

    // Back online
    makeStationOperational($stationId);
    runWeatherStationSync();
    $lifecycle['restored'] = getStationHash($stationId);

    return $lifecycle;
}

// ===== WEATHER STATION ASSERTIONS =====

function assertStationHasHash(TestWeatherStation $station): Hash
{
    $hash = Hash::where('hashable_type', 'test_weather_station')
        ->where('hashable_id', $station->id)
        ->first();

    expect($hash)->not->toBeNull();
    expect($hash->attribute_hash)->not->toBeNull();
    expect($hash->composite_hash)->not->toBeNull();

    return $hash;
}

function assertStationInScope(TestWeatherStation $station): void
{
    expect($station->location)->toBe('Bayern', 'Station should be in Bayern');
    expect($station->status)->toBe('active', 'Station should be active');

    $hasQualifyingAnemometer = $station->anemometers()
        ->where('max_speed', '>', 20.0)
        ->exists();

    expect($hasQualifyingAnemometer)->toBeTrue('Station should have anemometer with max_speed > 20');
}

function assertStationOutOfScope(TestWeatherStation $station): void
{
    $inBayern = $station->location === 'Bayern';
    $isActive = $station->status === 'active';
    $hasQualifyingAnemometer = $station->anemometers()
        ->where('max_speed', '>', 20.0)
        ->exists();

    expect($inBayern && $isActive && $hasQualifyingAnemometer)->toBeFalse(
        'Station should not meet all scope criteria'
    );
}

function assertStationHasCompleteSensorSetup(TestWeatherStation $station): void
{
    expect($station->windvanes()->exists())->toBeTrue('Station should have at least one windvane');
    expect($station->anemometers()->exists())->toBeTrue('Station should have at least one anemometer');
}

// ===== QUICK STATION CREATION HELPERS =====

function createStationInBayern(array $overrides = []): TestWeatherStation
{
    return TestWeatherStation::create(array_merge([
        'name' => 'Bayern Station '.uniqid(),
        'location' => 'Bayern',
        'latitude' => 48.1351 + (rand(0, 100) / 1000),
        'longitude' => 11.5820 + (rand(0, 100) / 1000),
        'status' => 'active',
        'is_operational' => true,
    ], $overrides));
}

function createStationInBayernWithoutEvt(array $overrides = []): TestWeatherStation
{
    return TestWeatherStation::withoutEvents(function () use ($overrides) {
        return TestWeatherStation::create(array_merge([
            'name' => 'Bayern Station '.uniqid(),
            'location' => 'Bayern',
            'latitude' => 48.1351 + (rand(0, 100) / 1000),
            'longitude' => 11.5820 + (rand(0, 100) / 1000),
            'status' => 'active',
            'is_operational' => true,
        ], $overrides));
    });
}

function createWindvaneForStation(int $stationId, float $direction = 90.0): TestWindvane
{
    return TestWindvane::withoutEvents(function () use ($stationId, $direction) {
        return TestWindvane::create([
            'weather_station_id' => $stationId,
            'direction' => $direction,
            'accuracy' => 95.0 + (rand(0, 40) / 10), // 95.0 - 99.0
            'calibration_date' => '2024-01-'.str_pad(rand(1, 28), 2, '0', STR_PAD_LEFT),
        ]);
    });
}

function createAnemometerForStation(int $stationId, float $maxSpeed = 25.0): TestAnemometer
{
    return TestAnemometer::withoutEvents(function () use ($stationId, $maxSpeed) {
        return TestAnemometer::create([
            'weather_station_id' => $stationId,
            'wind_speed' => $maxSpeed * 0.6, // Current speed is 60% of max
            'max_speed' => $maxSpeed,
            'sensor_type' => 'ultrasonic',
        ]);
    });
}

// ===== COMPLETE STATION SETUP HELPERS =====

function createCompleteWeatherStation(string $location = 'Bayern', float $maxSpeed = 25.0): array
{
    $station = TestWeatherStation::withoutEvents(function () use ($location) {
        return TestWeatherStation::create([
            'name' => "{$location} Station " . uniqid(),
            'location' => $location,
            'latitude' => 48.1351 + (rand(0, 100) / 1000),
            'longitude' => 11.5820 + (rand(0, 100) / 1000),
            'status' => 'active',
            'is_operational' => true,
        ]);
    });

    $windvane = createWindvaneForStation($station->id);
    $anemometer = createAnemometerForStation($station->id, $maxSpeed);

    return [
        'station' => $station,
        'windvane' => $windvane,
        'anemometer' => $anemometer,
    ];
}

function createStationWithMultipleSensors(int $windvaneCount = 2, int $anemometerCount = 2): array
{
    $station = createStationInBayernWithoutEvt();

    $windvanes = [];
    for ($i = 0; $i < $windvaneCount; $i++) {
        $windvanes[] = createWindvaneForStation($station->id, $i * 90.0);
    }

    $anemometers = [];
    for ($i = 0; $i < $anemometerCount; $i++) {
        $anemometers[] = createAnemometerForStation($station->id, 20.0 + ($i * 5));
    }

    return [
        'station' => $station,
        'windvanes' => $windvanes,
        'anemometers' => $anemometers,
    ];
}

function createQualifyingStation(): TestWeatherStation
{
    $data = createCompleteWeatherStation('Bayern', 25.0); // max_speed > 20
    return $data['station'];
}

function createNonQualifyingStation(string $reason = 'location'): TestWeatherStation
{
    switch ($reason) {
        case 'location':
            $data = createCompleteWeatherStation('Berlin', 25.0);
            break;
        case 'status':
            $station = createStationInBayernWithoutEvt(['status' => 'inactive']);
            createWindvaneForStation($station->id);
            createAnemometerForStation($station->id, 25.0);
            return $station;
        case 'speed':
            $data = createCompleteWeatherStation('Bayern', 15.0); // max_speed < 20
            break;
        case 'no_sensors':
            return createStationInBayernWithoutEvt();
        default:
            $data = createCompleteWeatherStation('Berlin', 15.0);
    }

    return $data['station'] ?? createStationInBayernWithoutEvt();
}
