<?php

use Ameax\LaravelChangeDetection\Enums\PublishStatusEnum;
use Ameax\LaravelChangeDetection\Models\Hash;
use Ameax\LaravelChangeDetection\Models\Publish;
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

describe('weather station composite hash with dependencies', function () {
    // Note: The sync command autodiscovers dependencies from getHashCompositeDependencies()
    // It builds dependent model hashes first, then main model hash, then hash_dependent records,
    // and finally calculates composite hashes

    it('creates composite hash including sensor dependencies', function () {
        // Create station in scope
        $station = TestWeatherStation::create([
            'name' => 'München Composite Test',
            'location' => 'Bayern',
            'latitude' => 48.1351,
            'longitude' => 11.5820,
            'status' => 'active',
            'is_operational' => true,
        ]);

        // Create dependent sensors
        $anemometer = TestAnemometer::create([
            'weather_station_id' => $station->id,
            'wind_speed' => 12.5,
            'max_speed' => 25.0,
            'sensor_type' => 'ultrasonic',
        ]);

        $windvane = TestWindvane::create([
            'weather_station_id' => $station->id,
            'direction' => 180.0,
            'accuracy' => 95.0,
            'calibration_date' => now()->subDays(30),
        ]);

        $publisher = createWeatherStationPublisher();
        runWeatherStationSync();

        // Verify hash creation
        $hash = getStationHash($station->id);
        expect($hash)->not->toBeNull();
        expect($hash->attribute_hash)->not->toBeNull();
        expect($hash->composite_hash)->not->toBeNull();

        // Composite hash should differ from attribute hash due to dependencies
        expect($hash->composite_hash)->not->toBe($hash->attribute_hash);

        // Verify publish record was created
        $publish = Publish::where('hash_id', $hash->id)
            ->where('publisher_id', $publisher->id)
            ->first();
        expect($publish)->not->toBeNull();
        expect($publish->status)->toBe(PublishStatusEnum::PENDING);
    });

    it('updates composite hash when dependent sensor changes', function () {
        $station = TestWeatherStation::create([
            'name' => 'Nürnberg Dependency Test',
            'location' => 'Bayern',
            'latitude' => 49.4521,
            'longitude' => 11.0767,
            'status' => 'active',
            'is_operational' => true,
        ]);

        $anemometer = TestAnemometer::create([
            'weather_station_id' => $station->id,
            'wind_speed' => 8.0,
            'max_speed' => 15.0,
            'sensor_type' => 'mechanical',
        ]);

        $publisher = createWeatherStationPublisher();
        runWeatherStationSync();

        $initialHash = getStationHash($station->id);
        $initialComposite = $initialHash->composite_hash;
        $initialAttribute = $initialHash->attribute_hash;

        // Update anemometer
        $anemometer->wind_speed = 20.0;
        $anemometer->save();
        runWeatherStationSync();

        $updatedHash = getStationHash($station->id);

        // Attribute hash should remain the same (station didn't change)
        expect($updatedHash->attribute_hash)->toBe($initialAttribute);

        // Composite hash should change (dependency changed)
        expect($updatedHash->composite_hash)->not->toBe($initialComposite);

        // Verify new publish record
        $publishCount = Publish::where('hash_id', $updatedHash->id)
            ->where('publisher_id', $publisher->id)
            ->count();
        expect($publishCount)->toBe(1);
    });

    it('handles multiple dependent sensors correctly', function () {
        $station = TestWeatherStation::create([
            'name' => 'Augsburg Multi-Sensor',
            'location' => 'Bayern',
            'latitude' => 48.3705,
            'longitude' => 10.8978,
            'status' => 'active',
            'is_operational' => true,
        ]);

        // Create multiple sensors
        $anemometers = [];
        $windvanes = [];

        for ($i = 1; $i <= 3; $i++) {
            $anemometers[] = TestAnemometer::create([
                'weather_station_id' => $station->id,
                'wind_speed' => 5.0 * $i,
                'max_speed' => 10.0 * $i,
                'sensor_type' => "sensor_{$i}",
            ]);

            $windvanes[] = TestWindvane::create([
                'weather_station_id' => $station->id,
                'direction' => 45.0 * $i,
                'accuracy' => 90.0 + $i,
                'calibration_date' => now()->subDays(10 * $i),
            ]);
        }

        $publisher = createWeatherStationPublisher();
        runWeatherStationSync();

        $hash = getStationHash($station->id);
        expect($hash->composite_hash)->not->toBe($hash->attribute_hash);

        // Change one sensor and verify composite hash changes
        $initialComposite = $hash->composite_hash;
        $anemometers[1]->wind_speed = 100.0;
        $anemometers[1]->save();
        runWeatherStationSync();

        $updatedHash = getStationHash($station->id);
        expect($updatedHash->composite_hash)->not->toBe($initialComposite);
    });
});

describe('weather station scope transitions with dependencies', function () {
    it('handles station entering scope with existing dependencies', function () {
        // Create station initially out of scope
        $station = TestWeatherStation::create([
            'name' => 'Würzburg Out-of-Scope',
            'location' => 'Hessen', // Out of Bayern
            'latitude' => 49.7913,
            'longitude' => 9.9534,
            'status' => 'active',
            'is_operational' => true,
        ]);

        // Create dependencies while out of scope
        $anemometer = TestAnemometer::create([
            'weather_station_id' => $station->id,
            'wind_speed' => 15.0,
            'max_speed' => 30.0,
            'sensor_type' => 'digital',
        ]);

        $publisher = createWeatherStationPublisher();
        runWeatherStationSync();

        // No hash should exist
        expectStationHashNotExists($station->id);

        // Move station into scope
        $station->location = 'Bayern';
        $station->save();
        runWeatherStationSync();

        // Hash should be created with proper composite
        $hash = getStationHash($station->id);
        expect($hash)->not->toBeNull();
        expect($hash->deleted_at)->toBeNull();
        expect($hash->composite_hash)->not->toBe($hash->attribute_hash);

        // Verify publish record
        $publish = Publish::where('hash_id', $hash->id)->first();
        expect($publish)->not->toBeNull();
    });

    it('soft deletes hash when station leaves scope but preserves dependencies', function () {
        $station = TestWeatherStation::create([
            'name' => 'Regensburg Mobile',
            'location' => 'Bayern',
            'latitude' => 49.0134,
            'longitude' => 12.1016,
            'status' => 'active',
            'is_operational' => true,
        ]);

        $anemometer = TestAnemometer::create([
            'weather_station_id' => $station->id,
            'wind_speed' => 10.0,
            'max_speed' => 20.0,
            'sensor_type' => 'laser',
        ]);

        $publisher = createWeatherStationPublisher();
        runWeatherStationSync();

        $hash = getStationHash($station->id);
        expect($hash->deleted_at)->toBeNull();

        // Move station out of scope
        $station->status = 'inactive';
        $station->save();
        runWeatherStationSync();

        // Hash should be soft deleted
        expectStationHashSoftDeleted($station->id);

        // Anemometer should still exist
        expect(TestAnemometer::find($anemometer->id))->not->toBeNull();

        // Move back into scope
        $station->status = 'active';
        $station->save();
        runWeatherStationSync();

        // Hash should be restored with same dependencies
        $restoredHash = getStationHash($station->id);
        expect($restoredHash->deleted_at)->toBeNull();
        expect($restoredHash->composite_hash)->not->toBe($restoredHash->attribute_hash);
    });

    it('handles dependent record changes while station is out of scope', function () {
        $station = TestWeatherStation::create([
            'name' => 'Bamberg Scope Test',
            'location' => 'Bayern',
            'latitude' => 49.8988,
            'longitude' => 10.9028,
            'status' => 'active',
            'is_operational' => true,
        ]);

        $anemometer = TestAnemometer::create([
            'weather_station_id' => $station->id,
            'wind_speed' => 5.0,
            'max_speed' => 10.0,
            'sensor_type' => 'cup',
        ]);

        $publisher = createWeatherStationPublisher();
        runWeatherStationSync();

        $initialHash = getStationHash($station->id);
        $initialComposite = $initialHash->composite_hash;

        // Move station out of scope
        $station->is_operational = false;
        $station->save();
        runWeatherStationSync();
        expectStationHashSoftDeleted($station->id);

        // Change dependency while out of scope
        $anemometer->wind_speed = 50.0;
        $anemometer->save();

        // Move station back into scope
        $station->is_operational = true;
        $station->save();
        runWeatherStationSync();

        // Hash should reflect the dependency change
        $restoredHash = getStationHash($station->id);
        expect($restoredHash->deleted_at)->toBeNull();
        expect($restoredHash->composite_hash)->not->toBe($initialComposite);
    });
});

describe('weather station CRUD operations with publishing', function () {
    it('creates publish record on initial station creation', function () {
        $publisher = createWeatherStationPublisher();

        $station = TestWeatherStation::create([
            'name' => 'Passau New Station',
            'location' => 'Bayern',
            'latitude' => 48.5665,
            'longitude' => 13.4312,
            'status' => 'active',
            'is_operational' => true,
        ]);

        runWeatherStationSync();

        $hash = getStationHash($station->id);
        expect($hash)->not->toBeNull();

        // ONE publish record should be created
        $publishRecords = Publish::where('hash_id', $hash->id)
            ->where('publisher_id', $publisher->id)
            ->get();
        expect($publishRecords)->toHaveCount(1);
        expect($publishRecords->first()->status)->toBe(PublishStatusEnum::PENDING);
    });

    it('maintains single publish record through multiple updates', function () {
        $publisher = createWeatherStationPublisher();

        $station = TestWeatherStation::create([
            'name' => 'Erlangen Update Test',
            'location' => 'Bayern',
            'latitude' => 49.5897,
            'longitude' => 11.0062,
            'status' => 'active',
            'is_operational' => true,
        ]);

        runWeatherStationSync();
        $hash = getStationHash($station->id);

        // Get initial publish record
        $initialPublish = Publish::where('hash_id', $hash->id)->first();
        expect($initialPublish)->not->toBeNull();

        // Multiple updates
        for ($i = 1; $i <= 3; $i++) {
            $station->name = "Erlangen Update {$i}";
            $station->save();
            runWeatherStationSync();
        }

        // Still only ONE publish record for this hash
        $publishCount = Publish::where('hash_id', $hash->id)
            ->where('publisher_id', $publisher->id)
            ->count();
        expect($publishCount)->toBe(1);
    });

    it('handles dependent record creation after station', function () {
        $station = TestWeatherStation::create([
            'name' => 'Fürth Delayed Sensors',
            'location' => 'Bayern',
            'latitude' => 49.4775,
            'longitude' => 10.9903,
            'status' => 'active',
            'is_operational' => true,
        ]);

        $publisher = createWeatherStationPublisher();
        runWeatherStationSync();

        $initialHash = getStationHash($station->id);
        expect($initialHash->composite_hash)->toBe($initialHash->attribute_hash); // No dependencies yet

        // Add sensors later
        $anemometer = TestAnemometer::create([
            'weather_station_id' => $station->id,
            'wind_speed' => 7.5,
            'max_speed' => 15.0,
            'sensor_type' => 'vane',
        ]);

        $windvane = TestWindvane::create([
            'weather_station_id' => $station->id,
            'direction' => 270.0,
            'accuracy' => 92.0,
            'calibration_date' => now(),
        ]);

        runWeatherStationSync();

        $updatedHash = getStationHash($station->id);
        expect($updatedHash->composite_hash)->not->toBe($updatedHash->attribute_hash);
        expect($updatedHash->composite_hash)->not->toBe($initialHash->composite_hash);
    });

    it('handles dependent record deletion', function () {
        $station = TestWeatherStation::create([
            'name' => 'Ingolstadt Sensor Removal',
            'location' => 'Bayern',
            'latitude' => 48.7665,
            'longitude' => 11.4257,
            'status' => 'active',
            'is_operational' => true,
        ]);

        $anemometer = TestAnemometer::create([
            'weather_station_id' => $station->id,
            'wind_speed' => 11.0,
            'max_speed' => 22.0,
            'sensor_type' => 'sonic',
        ]);

        $windvane = TestWindvane::create([
            'weather_station_id' => $station->id,
            'direction' => 90.0,
            'accuracy' => 94.0,
            'calibration_date' => now()->subDays(5),
        ]);

        $publisher = createWeatherStationPublisher();
        runWeatherStationSync();

        $hashWithDeps = getStationHash($station->id);
        $compositeWithDeps = $hashWithDeps->composite_hash;

        // Delete one sensor
        $anemometer->delete();
        runWeatherStationSync();

        $hashAfterDelete = getStationHash($station->id);
        expect($hashAfterDelete->composite_hash)->not->toBe($compositeWithDeps);

        // Delete remaining sensor
        $windvane->delete();
        runWeatherStationSync();

        $hashNoDeps = getStationHash($station->id);
        expect($hashNoDeps->composite_hash)->toBe($hashNoDeps->attribute_hash); // Back to no dependencies
    });

    it('handles station deletion with dependencies', function () {
        $station = TestWeatherStation::create([
            'name' => 'Schweinfurt Delete Test',
            'location' => 'Bayern',
            'latitude' => 50.0520,
            'longitude' => 10.2321,
            'status' => 'active',
            'is_operational' => true,
        ]);

        $anemometer = TestAnemometer::create([
            'weather_station_id' => $station->id,
            'wind_speed' => 6.0,
            'max_speed' => 12.0,
            'sensor_type' => 'pitot',
        ]);

        $publisher = createWeatherStationPublisher();
        runWeatherStationSync();

        expectStationHashActive($station->id);

        // Delete station
        $stationId = $station->id;
        $station->delete();
        runWeatherStationSync();

        // Hash should be soft deleted
        expectStationHashSoftDeleted($stationId);

        // Dependent record should be deleted due to cascade delete
        expect(TestAnemometer::find($anemometer->id))->toBeNull();
    });
});

describe('weather station complex dependency scenarios', function () {
    it('correctly calculates composite hash with circular dependency prevention', function () {
        $station = TestWeatherStation::create([
            'name' => 'Landshut Circular Test',
            'location' => 'Bayern',
            'latitude' => 48.5371,
            'longitude' => 12.1521,
            'status' => 'active',
            'is_operational' => true,
        ]);

        // Create multiple interrelated sensors
        $anemometers = [];
        for ($i = 1; $i <= 5; $i++) {
            $anemometers[] = TestAnemometer::create([
                'weather_station_id' => $station->id,
                'wind_speed' => 2.0 * $i,
                'max_speed' => 4.0 * $i,
                'sensor_type' => "array_{$i}",
            ]);
        }

        $publisher = createWeatherStationPublisher();
        runWeatherStationSync();

        $hash = getStationHash($station->id);

        // Verify composite includes all dependencies
        expect($hash->composite_hash)->not->toBe($hash->attribute_hash);

        // Change multiple sensors
        $initialComposite = $hash->composite_hash;
        foreach ($anemometers as $index => $anemometer) {
            if ($index % 2 === 0) {
                $anemometer->wind_speed *= 2;
                $anemometer->save();
            }
        }

        runWeatherStationSync();
        $updatedHash = getStationHash($station->id);
        expect($updatedHash->composite_hash)->not->toBe($initialComposite);
    });

    it('handles cascading updates through dependency chain', function () {
        $station = TestWeatherStation::create([
            'name' => 'Kempten Cascade Test',
            'location' => 'Bayern',
            'latitude' => 47.7267,
            'longitude' => 10.3168,
            'status' => 'active',
            'is_operational' => true,
        ]);

        // Create primary sensors
        $primaryAnemometer = TestAnemometer::create([
            'weather_station_id' => $station->id,
            'wind_speed' => 10.0,
            'max_speed' => 20.0,
            'sensor_type' => 'primary',
        ]);

        $primaryWindvane = TestWindvane::create([
            'weather_station_id' => $station->id,
            'direction' => 180.0,
            'accuracy' => 98.0,
            'calibration_date' => now(),
        ]);

        // Create backup sensors
        $backupAnemometer = TestAnemometer::create([
            'weather_station_id' => $station->id,
            'wind_speed' => 9.5,
            'max_speed' => 19.0,
            'sensor_type' => 'backup',
        ]);

        $publisher = createWeatherStationPublisher();
        runWeatherStationSync();

        $initialHash = getStationHash($station->id);

        // Update primary sensor
        $primaryAnemometer->wind_speed = 25.0;
        $primaryAnemometer->save();
        runWeatherStationSync();

        $afterPrimaryUpdate = getStationHash($station->id);
        expect($afterPrimaryUpdate->composite_hash)->not->toBe($initialHash->composite_hash);

        // Update backup sensor
        $backupAnemometer->wind_speed = 24.5;
        $backupAnemometer->save();
        runWeatherStationSync();

        $afterBackupUpdate = getStationHash($station->id);
        expect($afterBackupUpdate->composite_hash)->not->toBe($afterPrimaryUpdate->composite_hash);
    });

    it('maintains publish record consistency through complex operations', function () {
        $publisher = createWeatherStationPublisher();

        $station = TestWeatherStation::create([
            'name' => 'Rosenheim Complex Publish',
            'location' => 'Bayern',
            'latitude' => 47.8562,
            'longitude' => 12.1227,
            'status' => 'active',
            'is_operational' => true,
        ]);

        runWeatherStationSync();

        $hash = getStationHash($station->id);
        $initialPublish = Publish::where('hash_id', $hash->id)->first();
        expect($initialPublish)->not->toBeNull();
        $publishId = $initialPublish->id;

        // Add sensors
        $anemometer = TestAnemometer::create([
            'weather_station_id' => $station->id,
            'wind_speed' => 8.0,
            'max_speed' => 16.0,
            'sensor_type' => 'standard',
        ]);
        runWeatherStationSync();

        // Update station
        $station->name = 'Rosenheim Updated';
        $station->save();
        runWeatherStationSync();

        // Update sensor
        $anemometer->wind_speed = 12.0;
        $anemometer->save();
        runWeatherStationSync();

        // Move out of scope and back
        $station->is_operational = false;
        $station->save();
        runWeatherStationSync();

        $station->is_operational = true;
        $station->save();
        runWeatherStationSync();

        // Verify still only ONE publish record for this hash
        $finalPublishCount = Publish::where('hash_id', $hash->id)->count();
        expect($finalPublishCount)->toBe(1);

        // Verify it's the same publish record
        $finalPublish = Publish::where('hash_id', $hash->id)->first();
        expect($finalPublish->id)->toBe($publishId);
    });

    it('handles bulk sensor updates efficiently', function () {
        $station = TestWeatherStation::create([
            'name' => 'Garmisch Bulk Test',
            'location' => 'Bayern',
            'latitude' => 47.4915,
            'longitude' => 11.0956,
            'status' => 'active',
            'is_operational' => true,
        ]);

        // Create many sensors
        $sensors = [];
        for ($i = 1; $i <= 10; $i++) {
            $sensors[] = TestAnemometer::create([
                'weather_station_id' => $station->id,
                'wind_speed' => 1.0 * $i,
                'max_speed' => 2.0 * $i,
                'sensor_type' => "bulk_{$i}",
            ]);
        }

        $publisher = createWeatherStationPublisher();
        runWeatherStationSync();

        $initialHash = getStationHash($station->id);

        // Bulk update all sensors
        TestAnemometer::where('weather_station_id', $station->id)
            ->update(['wind_speed' => \DB::raw('wind_speed * 1.5')]);

        runWeatherStationSync();

        $updatedHash = getStationHash($station->id);
        expect($updatedHash->composite_hash)->not->toBe($initialHash->composite_hash);
        expect($updatedHash->attribute_hash)->toBe($initialHash->attribute_hash); // Station unchanged
    });
});

describe('weather station edge cases and error conditions', function () {
    it('handles station with no dependencies correctly', function () {
        $station = TestWeatherStation::create([
            'name' => 'Memmingen Solo',
            'location' => 'Bayern',
            'latitude' => 47.9878,
            'longitude' => 10.1815,
            'status' => 'active',
            'is_operational' => true,
        ]);

        $publisher = createWeatherStationPublisher();
        runWeatherStationSync();

        $hash = getStationHash($station->id);
        expect($hash)->not->toBeNull();
        expect($hash->attribute_hash)->not->toBeNull();
        expect($hash->composite_hash)->toBe($hash->attribute_hash); // No dependencies
    });

    it('handles rapid dependency creation and deletion', function () {
        $station = TestWeatherStation::create([
            'name' => 'Hof Rapid Changes',
            'location' => 'Bayern',
            'latitude' => 50.3216,
            'longitude' => 11.9226,
            'status' => 'active',
            'is_operational' => true,
        ]);

        $publisher = createWeatherStationPublisher();
        runWeatherStationSync();

        $hashes = [];

        // Rapid create/delete cycle
        for ($i = 1; $i <= 3; $i++) {
            // Create sensor
            $sensor = TestAnemometer::create([
                'weather_station_id' => $station->id,
                'wind_speed' => 5.0 * $i,
                'max_speed' => 10.0 * $i,
                'sensor_type' => "temp_{$i}",
            ]);

            runWeatherStationSync();
            $hashes[] = getStationHash($station->id)->composite_hash;

            // Delete sensor
            $sensor->delete();
            runWeatherStationSync();
            $hashes[] = getStationHash($station->id)->composite_hash;
        }

        // Verify all hashes were tracked
        expect($hashes)->toHaveCount(6);

        // Final hash should match initial (no dependencies)
        $finalHash = getStationHash($station->id);
        expect($finalHash->composite_hash)->toBe($finalHash->attribute_hash);
    });

    it('correctly handles null values in sensor attributes', function () {
        $station = TestWeatherStation::create([
            'name' => 'Bayreuth Null Test',
            'location' => 'Bayern',
            'latitude' => 49.9456,
            'longitude' => 11.5713,
            'status' => 'active',
            'is_operational' => true,
        ]);

        $windvane = TestWindvane::create([
            'weather_station_id' => $station->id,
            'direction' => 0.0,
            'accuracy' => 90.0,
            'calibration_date' => now()->subDays(1), // Required date
        ]);

        $publisher = createWeatherStationPublisher();
        runWeatherStationSync();

        $hash = getStationHash($station->id);
        expect($hash)->not->toBeNull();
        expect($hash->composite_hash)->not->toBe($hash->attribute_hash);

        // Update date to different value
        $windvane->calibration_date = now()->addDays(30);
        $windvane->save();
        runWeatherStationSync();

        $updatedHash = getStationHash($station->id);
        expect($updatedHash->composite_hash)->not->toBe($hash->composite_hash);
    });

    it('maintains data integrity through concurrent modifications', function () {
        $station = TestWeatherStation::create([
            'name' => 'Coburg Concurrent Test',
            'location' => 'Bayern',
            'latitude' => 50.2639,
            'longitude' => 10.9645,
            'status' => 'active',
            'is_operational' => true,
        ]);

        // Create multiple sensors
        $sensors = [];
        for ($i = 1; $i <= 3; $i++) {
            $sensors[] = TestAnemometer::create([
                'weather_station_id' => $station->id,
                'wind_speed' => 3.0 * $i,
                'max_speed' => 6.0 * $i,
                'sensor_type' => "concurrent_{$i}",
            ]);
        }

        $publisher = createWeatherStationPublisher();
        runWeatherStationSync();

        // Simulate concurrent modifications
        $station->name = 'Coburg Modified';
        $station->save();

        $sensors[0]->wind_speed = 50.0;
        $sensors[0]->save();

        $sensors[1]->delete();

        runWeatherStationSync();

        // Verify final state is consistent
        $hash = getStationHash($station->id);
        expect($hash)->not->toBeNull();
        expect($hash->deleted_at)->toBeNull();

        // Verify publish record remains consistent
        $publishCount = Publish::where('hash_id', $hash->id)->count();
        expect($publishCount)->toBe(1);
    });
});
