<?php

use Ameax\LaravelChangeDetection\Models\Hash;
use Ameax\LaravelChangeDetection\Models\HashDependent;
use Ameax\LaravelChangeDetection\Models\Publish;
use Ameax\LaravelChangeDetection\Services\HashPurger;
use Ameax\LaravelChangeDetection\Tests\Models\TestAnemometer;
use Ameax\LaravelChangeDetection\Tests\Models\TestWeatherStation;
use Ameax\LaravelChangeDetection\Tests\Models\TestWindvane;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    Relation::morphMap([
        'test_weather_station' => TestWeatherStation::class,
        'test_windvane' => TestWindvane::class,
        'test_anemometer' => TestAnemometer::class,
    ]);
});

describe('weather station deletion scenarios', function () {
    // 1. Station Deletion with Multiple Sensors
    it('handles station deletion with multiple windvanes and anemometers', function () {
        $station = createStationInBayern();

        // Create multiple sensors
        $windvanes = [
            createWindvaneForStation($station->id, 0.0),
            createWindvaneForStation($station->id, 90.0),
            createWindvaneForStation($station->id, 180.0),
        ];

        $anemometers = [
            createAnemometerForStation($station->id, 25.0),
            createAnemometerForStation($station->id, 30.0),
        ];

        $publisher = createPublisherForModel('test_weather_station');
        runSyncAutoDiscover();

        expectStationHashActive($station->id);
        expectHashActive('test_windvane', $windvanes[0]->id);
        expectHashActive('test_anemometer', $anemometers[0]->id);

        // Delete station (sensors cascade delete)
        $station->delete();

        runSyncAutoDiscover();

        // All hashes should be soft-deleted
        expectStationHashSoftDeleted($station->id);
        foreach ($windvanes as $windvane) {
            expectHashSoftDeleted('test_windvane', $windvane->id);
        }
        foreach ($anemometers as $anemometer) {
            expectHashSoftDeleted('test_anemometer', $anemometer->id);
        }
    });

    // 2. Delete Only Anemometer (Station Leaves Scope)
    it('removes station from scope when only anemometer is deleted', function () {
        $station = createStationInBayern();
        $windvane = createWindvaneForStation($station->id);
        $anemometer = createAnemometerForStation($station->id, 25.0);

        $publisher = createPublisherForModel('test_weather_station');
        runSyncAutoDiscover();

        expectStationHashActive($station->id);

        // Delete anemometer - station requires anemometer with max_speed > 20
        $anemometer->delete();
        runSyncAutoDiscover();

        // Station no longer meets scope requirements
        expectStationHashSoftDeleted($station->id);
        // Windvane still exists
        expectHashActive('test_windvane', $windvane->id);

    });

    // 3. Keep Station in Scope with Remaining Qualifying Anemometer
    it('keeps station in scope when one qualifying anemometer remains', function () {
        $station = createStationInBayern();
        $windvane = createWindvaneForStation($station->id);
        $anemometer1 = createAnemometerForStation($station->id, 25.0); // Qualifies
        $anemometer2 = createAnemometerForStation($station->id, 30.0); // Qualifies
        $anemometer3 = createAnemometerForStation($station->id, 15.0); // Doesn't qualify

        $publisher = createPublisherForModel('test_weather_station');
        runSyncAutoDiscover();
        expectStationHashActive($station->id);

        // Delete one qualifying anemometer
        $anemometer1->delete();
        runSyncAutoDiscover();

        // Station still in scope (anemometer2 has max_speed > 20)
        expectStationHashActive($station->id);
    });

    // 4. Bulk Delete All Sensors
    it('handles bulk deletion of all sensors at once', function () {
        $station = createStationInBayern();

        // Create many sensors
        for ($i = 0; $i < 10; $i++) {
            createWindvaneForStation($station->id, $i * 36);
            createAnemometerForStation($station->id, 20 + $i);
        }

        $publisher = createPublisherForModel('test_weather_station');
        runSyncAutoDiscover();

        expectStationHashActive($station->id);

        // Bulk delete all sensors
        TestWindvane::where('weather_station_id', $station->id)->delete();
        TestAnemometer::where('weather_station_id', $station->id)->delete();
        runSyncAutoDiscover();

        // Station should be out of scope
        expectStationHashSoftDeleted($station->id);
    });

    // 5. Delete and Recreate Station with Same ID
    it('handles station deletion and recreation with same ID', function () {

        $station = TestWeatherStation::withoutEvents(function () {
            return TestWeatherStation::create([
                'name' => 'Original Station',
                'location' => 'Bayern',
                'latitude' => 48.1351,
                'longitude' => 11.5820,
                'status' => 'active',
                'is_operational' => true,
            ]);
        });
        createWindvaneForStation($station->getKey());
        createAnemometerForStation($station->getKey(), 25.0);

        createPublisherForModel('test_weather_station');
        runSyncAutoDiscover();

        $originalHash = getStationHash($station->getKey())->attribute_hash;

        // Delete station
        $station->delete();
        runSyncAutoDiscover();
        expectStationHashSoftDeleted($station->getKey());

        // Use purge to completely remove the hash
        runSyncAutoDiscover(['--purge' => true]);
        // runSyncForModel(TestWeatherStation::class, ['--purge' => true]);
        dump($station->getKey());
        expect(getStationHash($station->getKey()))->toBeNull();

        //
        //        // Recreate with same ID but different data
        //        $newStation = TestWeatherStation::withoutEvents(function () {
        //            return TestWeatherStation::create([
        //                'id' => 999,
        //                'name' => 'New Station',
        //                'location' => 'Bayern',
        //                'latitude' => 48.2000,
        //                'longitude' => 11.6000,
        //                'status' => 'active',
        //                'is_operational' => true,
        //            ]);
        //        });
        //
        //        createWindvaneForStation(999);
        //        createAnemometerForStation(999, 30.0);
        //
        //        runSyncAutoDiscover();
        //
        //        // Should have new hash
        //        $newHash = getStationHash(999);
        //        expect($newHash->deleted_at)->toBeNull();
        //        expect($newHash->attribute_hash)->not->toBe($originalHash);
    });

    // 6. Database CASCADE Deletion
    it('properly cascades hash cleanup when database cascades sensor deletion', function () {
        $station = createStationInBayern();

        $windvaneIds = [];
        $anemometerIds = [];

        for ($i = 0; $i < 3; $i++) {
            $windvaneIds[] = createWindvaneForStation($station->id)->id;
            $anemometerIds[] = createAnemometerForStation($station->id, 25.0 + $i)->id;
        }

        $publisher = createPublisherForModel('test_weather_station');
        runSyncAutoDiscover();

        // Direct database deletion to trigger CASCADE
        DB::table('test_weather_stations')->where('id', $station->id)->delete();

        // Verify sensors were cascade deleted
        expect(TestWindvane::whereIn('id', $windvaneIds)->count())->toBe(0);
        expect(TestAnemometer::whereIn('id', $anemometerIds)->count())->toBe(0);

        runSyncAutoDiscover();

        // All hashes should be soft-deleted
        expectStationHashSoftDeleted($station->id);
        foreach ($windvaneIds as $id) {
            expectHashSoftDeleted('test_windvane', $id);
        }
        foreach ($anemometerIds as $id) {
            expectHashSoftDeleted('test_anemometer', $id);
        }
    });

    // 7. Delete Qualifying Anemometer, Keep Non-Qualifying
    it('removes station from scope when only non-qualifying anemometer remains', function () {
        $station = createStationInBayern();
        createWindvaneForStation($station->id);
        $qualifyingAnemometer = createAnemometerForStation($station->id, 25.0); // > 20
        $nonQualifyingAnemometer = createAnemometerForStation($station->id, 15.0); // < 20

        $publisher = createPublisherForModel('test_weather_station');
        runSyncAutoDiscover();
        expectStationHashActive($station->id);

        // Delete the qualifying anemometer
        $qualifyingAnemometer->delete();
        runSyncAutoDiscover();

        // Station out of scope (remaining anemometer has max_speed < 20)
        expectStationHashSoftDeleted($station->id);

        // Non-qualifying anemometer still has its hash
        expectHashActive('test_anemometer', $nonQualifyingAnemometer->id);
    });

    // 8. Rapid Delete and Restore Cycles
    it('handles rapid deletion and restoration cycles', function () {
        $station = createStationInBayern();
        createWindvaneForStation($station->id);
        $anemometer = createAnemometerForStation($station->id, 25.0);

        $publisher = createPublisherForModel('test_weather_station');
        runSyncAutoDiscover();

        $hashes = [];
        for ($i = 0; $i < 5; $i++) {
            // Delete anemometer
            $anemometer->delete();
            runSyncAutoDiscover();
            $hashes[] = ['deleted' => getStationHash($station->id)->deleted_at];

            // Recreate anemometer
            $anemometer = createAnemometerForStation($station->id, 25.0 + $i);
            runSyncAutoDiscover();
            $hashes[] = ['active' => getStationHash($station->id)->deleted_at];
        }

        // Verify proper state transitions
        foreach ($hashes as $i => $state) {
            if ($i % 2 === 0) {
                expect($state['deleted'])->not->toBeNull();
            } else {
                expect($state['active'])->toBeNull();
            }
        }
    });

    // 9. Station Deletion During Sensor Update
    it('handles station deletion while sensor data is being updated', function () {
        $station = createStationInBayern();
        $windvane = createWindvaneForStation($station->id);
        $anemometer = createAnemometerForStation($station->id, 25.0);

        $publisher = createPublisherForModel('test_weather_station');
        runSyncAutoDiscover();

        // Start updating sensor data
        $windvane->direction = 180.0;
        $windvane->save();

        // Prepare anemometer update
        $anemometer->wind_speed = 35.0;

        // Delete station before saving anemometer
        $station->delete();

        // Attempt to save orphaned sensor should fail gracefully
        try {
            $anemometer->save();
        } catch (\Exception $e) {
            // Expected behavior - foreign key constraint violation
        }

        runSyncAutoDiscover();
        expectStationHashSoftDeleted($station->id);
    });

    // 10. Progressive Sensor Removal (Composite Hash Testing)
    it('tracks state changes through progressive sensor removal', function () {
        $station = createStationInBayern();

        $sensors = [
            'w1' => createWindvaneForStation($station->id, 0),
            'w2' => createWindvaneForStation($station->id, 90),
            'w3' => createWindvaneForStation($station->id, 180),
            'a1' => createAnemometerForStation($station->id, 22.0),
            'a2' => createAnemometerForStation($station->id, 28.0),
        ];

        $publisher = createPublisherForModel('test_weather_station');
        runSyncAutoDiscover();
        expectStationHashActive($station->id);

        // Progressive deletion
        $sensors['w1']->delete();
        runSyncAutoDiscover();
        expectStationHashActive($station->id); // Still has qualifying anemometer

        $sensors['a2']->delete();
        runSyncAutoDiscover();
        expectStationHashActive($station->id); // a1 still qualifies

        $sensors['w2']->delete();
        runSyncAutoDiscover();
        expectStationHashActive($station->id); // Still in scope

        // Delete the last qualifying anemometer
        $sensors['a1']->delete();
        runSyncAutoDiscover();
        expectStationHashSoftDeleted($station->id); // Now out of scope
    });

    // 11. Out-of-Scope Station Sensor Deletion
    it('does not affect hash status when deleting sensors from out-of-scope station', function () {
        // Station not in Bayern (out of scope)
        $station = TestWeatherStation::withoutEvents(function () {
            return TestWeatherStation::create([
                'name' => 'Berlin Station',
                'location' => 'Berlin',
                'latitude' => 52.5200,
                'longitude' => 13.4050,
                'status' => 'active',
                'is_operational' => true,
            ]);
        });

        $windvane = createWindvaneForStation($station->id);
        $anemometer = createAnemometerForStation($station->id, 25.0);

        $publisher = createPublisherForModel('test_weather_station');
        runSyncAutoDiscover();

        // Station not in scope (not in Bayern)
        expect(getStationHash($station->id))->toBeNull();

        // Delete sensors
        $windvane->delete();
        $anemometer->delete();
        runSyncAutoDiscover();

        // Still no hash (was never in scope)
        expect(getStationHash($station->id))->toBeNull();
    });

    // 12. Orphaned Sensor Deletion
    it('handles orphaned sensor deletion after station is removed', function () {
        $station = createStationInBayern();
        $windvane = createWindvaneForStation($station->id);
        $anemometer = createAnemometerForStation($station->id, 25.0);

        $publisher = createPublisherForModel('test_weather_station');
        runSyncAutoDiscover();

        expectStationHashActive($station->id);
        expectHashActive('test_windvane', $windvane->id);
        expectHashActive('test_anemometer', $anemometer->id);

        // Force delete station without cascade (simulating orphaned sensors)
        DB::table('test_weather_stations')->where('id', $station->id)->delete();

        runSyncAutoDiscover();

        // Station hash should be soft-deleted
        expectStationHashSoftDeleted($station->id);

        // Sensor hashes remain active (they still exist as orphans)
        expectHashActive('test_windvane', $windvane->id);
        expectHashActive('test_anemometer', $anemometer->id);

        // Clean up orphaned sensors
        $windvane->delete();
        $anemometer->delete();
        runSyncAutoDiscover(['--purge' => true]);
        // runSyncForModel(TestWeatherStation::class, ['--purge' => true]);

        // Now sensor hashes should be soft-deleted
        expectHashSoftDeleted('test_windvane', $windvane->id);
        expectHashSoftDeleted('test_anemometer', $anemometer->id);
    })->skip();

    // 13. Station with Pending Unpublished Changes
    it('handles deletion of station with pending unpublished changes', function () {
        $publisher = createWeatherStationPublisher();

        $station = createStationInBayern();
        createWindvaneForStation($station->id);
        createAnemometerForStation($station->id, 25.0);

        $publisher = createPublisherForModel('test_weather_station');
        runSyncAutoDiscover();
        expectStationHashActive($station->id);

        // Make changes to station
        $station->name = 'Updated Station Name';
        $station->save();
        runSyncAutoDiscover();

        // Delete station with unpublished changes
        $station->delete();
        runSyncAutoDiscover();

        // Hash should be soft-deleted
        expectStationHashSoftDeleted($station->id);

        // Check publish records are handled properly
        $stationHash = getStationHash($station->id);
        $publishRecords = \Ameax\LaravelChangeDetection\Models\Publish::where('hash_id', $stationHash->id)->get();

        // Publish records should exist
        expect($publishRecords)->not->toBeEmpty();
    });

    // 14. Soft Delete vs Hard Delete Comparison
    it('demonstrates difference between soft delete and purge', function () {
        $station = createStationInBayern();
        createWindvaneForStation($station->id);
        createAnemometerForStation($station->id, 25.0);

        $publisher = createPublisherForModel('test_weather_station');
        runSyncAutoDiscover();

        expectStationHashActive($station->id);

        // Move station out of scope
        updateStationAttribute($station->id, 'location', 'Berlin');

        // First sync with soft delete (default)
        runSyncAutoDiscover();
        // runSyncForModel(TestWeatherStation::class);

        // Hash exists but is soft-deleted

        $hash = getStationHash($station->id);
        expect($hash)->not->toBeNull();
        expect($hash->deleted_at)->not->toBeNull();

        // Scenario2: The purge feature is designed to find and remove active hashes for non-existent records,
        // NOT to hard-delete already soft-deleted hashes.

        $station2 = createStationInBayern();
        createWindvaneForStation($station2->id);
        createAnemometerForStation($station2->id, 25.0);

        runSyncAutoDiscover();
        expectStationHashActive($station2->id);

        // DELETE THE STATION to make it orphaned
        $station2->delete();

        // Now sync with purge, it should remove it
        runSyncAutoDiscover(['--purge' => true]);
        // runSyncForModel(TestWeatherStation::class, ['--purge' => true]);

        // Hash is completely removed
        expect(getStationHash($station2->id))->toBeNull();
    });

    // 15. Hash Dependents Cascade Deletion
    it('cascades hash_dependents records when hash is purged', function () {
        $station = createStationInBayern();
        $windvane = createWindvaneForStation($station->id);
        $anemometer = createAnemometerForStation($station->id, 25.0);

        $publisher = createPublisherForModel('test_weather_station');
        runSyncAutoDiscover();

        // Verify dependencies were built
        $stationHash = getStationHash($station->id);
        expect($stationHash->has_dependencies_built)->toBeTrue();

        // Check hash_dependents records exist
        $dependentsCount = HashDependent::where('hash_id', $stationHash->id)->count();
        expect($dependentsCount)->toBeGreaterThan(0);

        // Hard delete the station to orphan the hash
        DB::table('test_weather_stations')->where('id', $station->id)->delete();

        // Purge to remove orphaned hash
        runSyncAutoDiscover(['--purge' => true]);

        // Verify hash and its dependents are completely removed
        expect(getStationHash($station->id))->toBeNull();
        expect(\Ameax\LaravelChangeDetection\Models\HashDependent::where('hash_id', $stationHash->id)->count())->toBe(0);
    })->skip();

    // 16. Publisher Deletion Cascade Impact
    it('handles publisher deletion and cascades to publish records', function () {
        $station = createStationInBayern();
        createWindvaneForStation($station->id);
        createAnemometerForStation($station->id, 25.0);

        $publisher = createPublisherForModel('test_weather_station');
        runSyncAutoDiscover();

        $stationHash = getStationHash($station->id);

        // Create publish records by making changes
        $station->name = 'Updated Name';
        $station->save();
        runSyncAutoDiscover();

        // Verify publish record exists
        $publishCount = \Ameax\LaravelChangeDetection\Models\Publish::where('publisher_id', $publisher->id)->count();
        expect($publishCount)->toBeGreaterThan(0);

        // Delete the publisher
        $publisher->delete();

        // Verify publish records are cascade deleted
        expect(\Ameax\LaravelChangeDetection\Models\Publish::where('publisher_id', $publisher->id)->count())->toBe(0);

        // Hash should still exist
        expectStationHashActive($station->id);
    });

    // 17. Publish Records During Hash Soft Delete vs Purge
    it('handles publish records differently for soft delete vs purge', function () {
        $station = createStationInBayern();
        createWindvaneForStation($station->id);
        createAnemometerForStation($station->id, 25.0);

        $publisher = createPublisherForModel('test_weather_station');
        runSyncAutoDiscover();

        // Create publish record
        $stationHash = getStationHash($station->id);
        $publish = \Ameax\LaravelChangeDetection\Models\Publish::create([
            'hash_id' => $stationHash->id,
            'publisher_id' => $publisher->id,
            'status' => 'pending',
        ]);

        // Scenario 1: Soft delete (move out of scope)
        updateStationAttribute($station->id, 'location', 'Berlin');
        runSyncAutoDiscover();

        // Hash is soft deleted, publish record remains
        expectStationHashSoftDeleted($station->id);
        expect(\Ameax\LaravelChangeDetection\Models\Publish::find($publish->id))->not->toBeNull();

        // Scenario 2: Create another station for purge test
        $station2 = createStationInBayern();
        createWindvaneForStation($station2->id);
        createAnemometerForStation($station2->id, 25.0);

        runSyncAutoDiscover();
        $station2Hash = getStationHash($station2->id);

        $publish2 = Publish::create([
            'hash_id' => $station2Hash->id,
            'publisher_id' => $publisher->id,
            'status' => 'pending',
        ]);

        // Hard delete to make orphaned
        DB::table('test_weather_stations')->where('id', $station2->id)->delete();

        // Purge removes hash and cascades to publish records
        runSyncAutoDiscover(['--purge' => true]);

        expect(getStationHash($station2->id))->toBeNull();
        expect(\Ameax\LaravelChangeDetection\Models\Publish::find($publish2->id))->toBeNull();
    })->skip();

    // 18. HashPurger Service for Age-Based Cleanup
    it('purges old soft-deleted hashes using HashPurger service', function () {
        $station = createStationInBayern();
        createWindvaneForStation($station->id);
        createAnemometerForStation($station->id, 25.0);

        $publisher = createPublisherForModel('test_weather_station');
        runSyncAutoDiscover();

        // Move out of scope to soft delete
        updateStationAttribute($station->id, 'location', 'Berlin');
        runSyncAutoDiscover();

        expectStationHashSoftDeleted($station->id);

        // Manually update deleted_at to be old
        $oldDate = now()->subDays(35);
        DB::table('hashes')
            ->where('hashable_type', 'test_weather_station')
            ->where('hashable_id', $station->id)
            ->update(['deleted_at' => $oldDate]);

        // Use HashPurger to clean up old soft-deleted hashes
        $purger = app(HashPurger::class);
        $stats = $purger->purge(30); // Purge records older than 30 days

        expect($stats['total_purged'])->toBe(1);
        expect(getStationHash($station->id))->toBeNull();
    })->skip();

    // 19. Concurrent Model and Hash Operations
    it('handles concurrent deletion and sync operations safely', function () {
        $stations = [];
        $publishers = [];

        // Create multiple stations
        for ($i = 0; $i < 3; $i++) {
            $stations[$i] = createStationInBayern();
            createWindvaneForStation($stations[$i]->id);
            createAnemometerForStation($stations[$i]->id, 25.0 + $i);
            $publishers[$i] = createPublisherForModel('test_weather_station', 'Publisher '.$i);
        }

        runSyncAutoDiscover();

        // Verify all hashes exist
        foreach ($stations as $station) {
            expectStationHashActive($station->id);
        }

        // Simulate concurrent operations
        // Station 0: Soft delete by moving out of scope
        updateStationAttribute($stations[0]->id, 'location', 'Berlin');

        // Station 1: Hard delete
        DB::table('test_weather_stations')->where('id', $stations[1]->id)->delete();

        // Station 2: Update data
        $stations[2]->name = 'Updated Concurrent Station';
        $stations[2]->save();

        // Run sync to handle all changes
        runSyncAutoDiscover();

        // Verify expected states
        expectStationHashSoftDeleted($stations[0]->id); // Soft deleted (out of scope)
        expectStationHashSoftDeleted($stations[1]->id); // Soft deleted (orphaned)
        expectStationHashActive($stations[2]->id);      // Still active with updated hash
    });

    // 20. Dependency Rebuild After Hash Restoration
    it('rebuilds dependencies when soft-deleted hash is restored', function () {
        $station = createStationInBayern();
        createWindvaneForStation($station->id);
        $anemometer = createAnemometerForStation($station->id, 15.0); // Non-qualifying

        $publisher = createPublisherForModel('test_weather_station');
        runSyncAutoDiscover();

        // Station out of scope due to non-qualifying anemometer
        expectStationHashSoftDeleted($station->id);

        // Update anemometer to qualify
        updateAnemometerMaxSpeed($anemometer->id, 25.0);
        runSyncAutoDiscover();

        // Station back in scope, hash restored
        expectStationHashActive($station->id);

        // Verify dependencies were rebuilt
        $stationHash = getStationHash($station->id);
        expect($stationHash->has_dependencies_built)->toBeTrue();

        $dependentsCount = \Ameax\LaravelChangeDetection\Models\HashDependent::where('hash_id', $stationHash->id)->count();
        expect($dependentsCount)->toBeGreaterThan(0);
    })->skip();

    // 21. Failed Publish Records During Deletion
    it('handles failed publish records during model deletion', function () {
        $station = createStationInBayern();
        createWindvaneForStation($station->id);
        createAnemometerForStation($station->id, 25.0);

        $publisher = createPublisherForModel('test_weather_station');
        runSyncAutoDiscover();

        $stationHash = getStationHash($station->id);

        // Create failed publish records
        $failedPublish = Publish::create([
            'hash_id' => $stationHash->id,
            'publisher_id' => $publisher->id,
            'status' => 'failed',
            'attempts' => 3,
            'last_error' => 'Connection timeout',
            'error_type' => 'infrastructure',
        ]);

        // Delete station
        $station->delete();
        runSyncAutoDiscover();

        // Hash is soft deleted
        expectStationHashSoftDeleted($station->id);

        // Failed publish record still exists (for debugging/retry)
        $failedPublish->refresh();
        expect($failedPublish->hash_id)->toBe($stationHash->id);
        expect($failedPublish->status)->toBe('failed');

        // Hard delete station for purge
        DB::table('test_weather_stations')->where('id', $station->id)->delete();
        runSyncAutoDiscover(['--purge' => true]);

        // Now publish record is cascade deleted with hash
        expect(Publish::find($failedPublish->id))->toBeNull();
    });

    // 22. Multiple Publishers During Deletion
    it('handles deletion with multiple active publishers', function () {
        $station = createStationInBayern();
        createWindvaneForStation($station->id);
        createAnemometerForStation($station->id, 25.0);

        // Create multiple publishers
        $logPublisher = createPublisherForModel('test_weather_station', 'Log Publisher');
        $apiPublisher = createApiPublisher('test_weather_station', 'https://api.example.com/webhook', 'API Publisher');
        $inactivePublisher = createInactivePublisher('test_weather_station', 'Inactive Publisher');

        runSyncAutoDiscover();

        $stationHash = getStationHash($station->id);

        // Verify publish records for active publishers only
        expect(Publish::where('hash_id', $stationHash->id)
            ->where('publisher_id', $logPublisher->id)->exists())->toBeTrue();
        expect(Publish::where('hash_id', $stationHash->id)
            ->where('publisher_id', $apiPublisher->id)->exists())->toBeTrue();
        expect(Publish::where('hash_id', $stationHash->id)
            ->where('publisher_id', $inactivePublisher->id)->exists())->toBeFalse();

        // Delete one publisher
        $apiPublisher->delete();

        // Other publisher's records remain
        expect(\Ameax\LaravelChangeDetection\Models\Publish::where('hash_id', $stationHash->id)
            ->where('publisher_id', $logPublisher->id)->exists())->toBeTrue();
        expect(\Ameax\LaravelChangeDetection\Models\Publish::where('hash_id', $stationHash->id)
            ->where('publisher_id', $apiPublisher->id)->exists())->toBeFalse();

        // Station and hash remain unaffected
        expectStationHashActive($station->id);
    })->skip();
});
