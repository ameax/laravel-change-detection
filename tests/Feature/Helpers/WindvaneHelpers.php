<?php

use Ameax\LaravelChangeDetection\Models\Hash;
use Ameax\LaravelChangeDetection\Models\Publisher;
use Ameax\LaravelChangeDetection\Tests\Models\TestWeatherStation;
use Ameax\LaravelChangeDetection\Tests\Models\TestWindvane;

// ===== WINDVANE-SPECIFIC SYNC FUNCTIONS =====

function runWindvaneSync(array $options = []): void
{
    runSyncForModel(TestWindvane::class, $options);
}

// ===== WINDVANE-SPECIFIC HASH FUNCTIONS =====

function getWindvaneHash(int $windvaneId): ?Hash
{
    return getHashForModel('test_windvane', $windvaneId);
}

function expectWindvaneHashExists(int $windvaneId): void
{
    expectHashExists('test_windvane', $windvaneId);
}

function expectWindvaneHashNotExists(int $windvaneId): void
{
    expectHashNotExists('test_windvane', $windvaneId);
}

function expectWindvaneHashActive(int $windvaneId): void
{
    expectHashActive('test_windvane', $windvaneId);
}

function expectWindvaneHashSoftDeleted(int $windvaneId): void
{
    expectHashSoftDeleted('test_windvane', $windvaneId);
}

function expectActiveWindvaneCount(int $count): void
{
    expectActiveHashCountForType('test_windvane', $count);
}

function expectTotalWindvaneCount(int $count): void
{
    expectTotalHashCountForType('test_windvane', $count);
}

// ===== WINDVANE-SPECIFIC PUBLISHER FUNCTIONS =====

function createWindvanePublisher(array $overrides = []): Publisher
{
    return createPublisherForModel('test_windvane', 'Test Windvane Publisher', $overrides);
}

// ===== WINDVANE DATA MANIPULATION =====

function updateWindvaneDirection(int $id, float $direction): TestWindvane
{
    $windvane = TestWindvane::find($id);
    $windvane->direction = $direction;
    $windvane->save();

    return $windvane;
}

function updateWindvaneAccuracy(int $id, float $accuracy): TestWindvane
{
    $windvane = TestWindvane::find($id);
    $windvane->accuracy = $accuracy;
    $windvane->save();

    return $windvane;
}

function updateWindvaneCalibrationDate(int $id, string $date): TestWindvane
{
    $windvane = TestWindvane::find($id);
    $windvane->calibration_date = $date;
    $windvane->save();

    return $windvane;
}

function updateWindvaneAttribute(int $id, string $attribute, $value): TestWindvane
{
    $windvane = TestWindvane::find($id);
    $windvane->$attribute = $value;
    $windvane->save();

    return $windvane;
}

// ===== WINDVANE TEST DATA SETUP =====

function createWindvane(array $attributes = []): TestWindvane
{
    $defaults = [
        'weather_station_id' => 1,
        'direction' => 180.0,
        'accuracy' => 95.0,
        'calibration_date' => '2024-01-15',
    ];

    return TestWindvane::create(array_merge($defaults, $attributes));
}

function createWindvaneWithoutEvents(array $attributes = []): TestWindvane
{
    return TestWindvane::withoutEvents(function () use ($attributes) {
        return createWindvane($attributes);
    });
}

function createCalibratedWindvane(int $stationId, float $accuracy = 98.0): TestWindvane
{
    return createWindvaneWithoutEvents([
        'weather_station_id' => $stationId,
        'direction' => 90.0,
        'accuracy' => $accuracy,
        'calibration_date' => now()->format('Y-m-d'),
    ]);
}

function createUncalibratedWindvane(int $stationId): TestWindvane
{
    return createWindvaneWithoutEvents([
        'weather_station_id' => $stationId,
        'direction' => 270.0,
        'accuracy' => 75.0,
        'calibration_date' => now()->subYear()->format('Y-m-d'),
    ]);
}

function setupWindvaneSet(int $stationId, int $count = 3): array
{
    $windvanes = [];

    for ($i = 0; $i < $count; $i++) {
        $windvanes[] = createWindvaneWithoutEvents([
            'weather_station_id' => $stationId,
            'direction' => $i * 90.0, // 0, 90, 180, 270...
            'accuracy' => 90.0 + $i * 2, // 90, 92, 94...
            'calibration_date' => now()->subDays($i * 30)->format('Y-m-d'),
        ]);
    }

    return $windvanes;
}

// ===== WINDVANE-SPECIFIC TEST SCENARIOS =====

function simulateWindDirectionChange(int $windvaneId, array $directions = [0, 90, 180, 270, 360]): array
{
    $hashes = [];

    foreach ($directions as $direction) {
        updateWindvaneDirection($windvaneId, $direction);
        runWindvaneSync();
        $hashes[$direction] = getWindvaneHash($windvaneId)?->attribute_hash;
    }

    return array_filter($hashes);
}

function simulateCalibrationDecay(int $windvaneId): array
{
    $stages = [];

    // Perfect calibration
    updateWindvaneAccuracy($windvaneId, 99.0);
    updateWindvaneCalibrationDate($windvaneId, now()->format('Y-m-d'));
    runWindvaneSync();
    $stages['perfect'] = getWindvaneHash($windvaneId);

    // Good calibration
    updateWindvaneAccuracy($windvaneId, 90.0);
    updateWindvaneCalibrationDate($windvaneId, now()->subMonths(3)->format('Y-m-d'));
    runWindvaneSync();
    $stages['good'] = getWindvaneHash($windvaneId);

    // Needs recalibration
    updateWindvaneAccuracy($windvaneId, 75.0);
    updateWindvaneCalibrationDate($windvaneId, now()->subYear()->format('Y-m-d'));
    runWindvaneSync();
    $stages['poor'] = getWindvaneHash($windvaneId);

    return $stages;
}

function rotateWindvaneFull360(int $windvaneId, int $steps = 8): array
{
    $angleIncrement = 360.0 / $steps;
    $measurements = [];

    for ($i = 0; $i < $steps; $i++) {
        $angle = $i * $angleIncrement;
        updateWindvaneDirection($windvaneId, $angle);
        runWindvaneSync();
        $measurements[$angle] = getWindvaneHash($windvaneId)?->attribute_hash;
    }

    return $measurements;
}

// ===== WINDVANE RELATIONSHIP HELPERS =====

function attachWindvaneToStation(TestWindvane $windvane, TestWeatherStation $station): TestWindvane
{
    $windvane->weather_station_id = $station->id;
    $windvane->save();

    return $windvane->fresh();
}

function detachWindvaneFromStation(TestWindvane $windvane): TestWindvane
{
    $windvane->weather_station_id = null;
    $windvane->save();

    return $windvane->fresh();
}

// ===== WINDVANE ASSERTION HELPERS =====

function assertWindvaneHasHash(TestWindvane $windvane): Hash
{
    $hash = Hash::where('hashable_type', 'test_windvane')
        ->where('hashable_id', $windvane->id)
        ->first();

    expect($hash)->not->toBeNull();
    expect($hash->attribute_hash)->not->toBeNull();

    return $hash;
}

function assertWindvanesHaveHashes(array $windvanes): void
{
    foreach ($windvanes as $windvane) {
        assertWindvaneHasHash($windvane);
    }
}
