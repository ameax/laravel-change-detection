<?php

use Ameax\LaravelChangeDetection\Models\Hash;
use Ameax\LaravelChangeDetection\Services\BulkHashProcessor;
use Ameax\LaravelChangeDetection\Tests\Models\TestCalibratedWindvane;
use Ameax\LaravelChangeDetection\Tests\Models\TestWeatherStation;
use Illuminate\Database\Eloquent\Relations\Relation;

beforeEach(function () {
    Relation::morphMap([
        'test_weather_station' => TestWeatherStation::class,
        'test_calibrated_windvane' => TestCalibratedWindvane::class,
    ]);
});

describe('combined scope filtering (own scope + parent scope)', function () {
    it('applies both own scope and parent scope when child has both', function () {
        // Create 2 weather stations: 1 in scope, 1 out of scope
        $bayernStation = TestWeatherStation::create([
            'name' => 'München Station',
            'location' => 'Bayern',
            'latitude' => 48.1351,
            'longitude' => 11.5820,
            'status' => 'active',
            'is_operational' => true,
        ]);

        $berlinStation = TestWeatherStation::create([
            'name' => 'Berlin Station',
            'location' => 'Berlin',
            'latitude' => 52.5200,
            'longitude' => 13.4050,
            'status' => 'active',
            'is_operational' => true,
        ]);

        // Create windvanes using TestCalibratedWindvane model
        // This model has TWO scopes:
        // 1. Own scope: accuracy >= 90 (calibrated)
        // 2. Parent scope: weather station in Bayern with active status

        // Bayern station (in scope) - calibrated windvane
        $bayernWindvaneCalibrated = TestCalibratedWindvane::create([
            'weather_station_id' => $bayernStation->id,
            'direction' => 180.0,
            'accuracy' => 95.0, // >= 90 (passes own scope)
            'calibration_date' => '2024-01-15',
        ]);

        // Bayern station (in scope) - uncalibrated windvane
        $bayernWindvaneUncalibrated = TestCalibratedWindvane::create([
            'weather_station_id' => $bayernStation->id,
            'direction' => 90.0,
            'accuracy' => 85.0, // < 90 (fails own scope)
            'calibration_date' => '2024-01-15',
        ]);

        // Berlin station (out of scope) - calibrated windvane
        $berlinWindvaneCalibrated = TestCalibratedWindvane::create([
            'weather_station_id' => $berlinStation->id,
            'direction' => 270.0,
            'accuracy' => 92.0, // >= 90 (passes own scope)
            'calibration_date' => '2024-01-15',
        ]);

        // Berlin station (out of scope) - uncalibrated windvane
        $berlinWindvaneUncalibrated = TestCalibratedWindvane::create([
            'weather_station_id' => $berlinStation->id,
            'direction' => 45.0,
            'accuracy' => 88.0, // < 90 (fails own scope)
            'calibration_date' => '2024-01-15',
        ]);

        $processor = app(BulkHashProcessor::class);

        // Process all weather stations first
        $processor->processChangedModels(TestWeatherStation::class);

        // Process calibrated windvanes with BOTH scopes applied
        $processor->processChangedModels(TestCalibratedWindvane::class);
        $processor->buildPendingDependencies(TestCalibratedWindvane::class);

        // Expected results with BOTH scopes applied:
        // - Bayern calibrated: YES (passes own scope AND parent in scope)
        // - Bayern uncalibrated: NO (fails own scope)
        // - Berlin calibrated: NO (parent out of scope)
        // - Berlin uncalibrated: NO (fails own scope AND parent out of scope)

        $bayernCalibratedHash = Hash::where('hashable_type', 'test_calibrated_windvane')
            ->where('hashable_id', $bayernWindvaneCalibrated->id)
            ->first();
        expect($bayernCalibratedHash)->not->toBeNull('Bayern calibrated windvane should have hash');

        $bayernUncalibratedHash = Hash::where('hashable_type', 'test_calibrated_windvane')
            ->where('hashable_id', $bayernWindvaneUncalibrated->id)
            ->first();
        expect($bayernUncalibratedHash)->toBeNull('Bayern uncalibrated windvane should NOT have hash (fails own scope)');

        $berlinCalibratedHash = Hash::where('hashable_type', 'test_calibrated_windvane')
            ->where('hashable_id', $berlinWindvaneCalibrated->id)
            ->first();
        expect($berlinCalibratedHash)->toBeNull('Berlin calibrated windvane should NOT have hash (parent out of scope)');

        $berlinUncalibratedHash = Hash::where('hashable_type', 'test_calibrated_windvane')
            ->where('hashable_id', $berlinWindvaneUncalibrated->id)
            ->first();
        expect($berlinUncalibratedHash)->toBeNull('Berlin uncalibrated windvane should NOT have hash (fails both scopes)');
    });
});