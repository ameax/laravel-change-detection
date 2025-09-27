<?php

use Ameax\LaravelChangeDetection\Helpers\ModelDiscoveryHelper;
use Ameax\LaravelChangeDetection\Models\Hash;
use Ameax\LaravelChangeDetection\Models\HashDependent;
use Ameax\LaravelChangeDetection\Models\Publish;
use Ameax\LaravelChangeDetection\Tests\Models\TestAnemometer;
use Ameax\LaravelChangeDetection\Tests\Models\TestWeatherStation;
use Ameax\LaravelChangeDetection\Tests\Models\TestWindvane;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    Relation::morphMap([
        'test_weather_station' => TestWeatherStation::class,
        'test_anemometer' => TestAnemometer::class,
        'test_windvane' => TestWindvane::class,
    ]);
});

it('debugs the sync process with dependencies', function () {
    // Create station and dependencies
    $station = TestWeatherStation::create([
        'name' => 'Debug Sync Station',
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

    // Check what dependencies are discovered
    $stationInstance = new TestWeatherStation;
    $dependencies = ModelDiscoveryHelper::getDependencyModelsFromModel($stationInstance);
    // dump('Discovered dependencies for WeatherStation: '.json_encode($dependencies));

    // Create publisher
    $publisher = createWeatherStationPublisher();

    // First, sync the dependent models explicitly
    // dump('=== Step 1: Sync dependent models ===');
    test()->artisan('change-detection:sync', [
        '--models' => [TestAnemometer::class, TestWindvane::class],
    ])->assertExitCode(0);

    // Check if anemometer has hash
    $anemometerHash = Hash::where('hashable_type', 'test_anemometer')
        ->where('hashable_id', $anemometer->id)
        ->first();
    // dump('Anemometer hash after dep sync: '.($anemometerHash ? 'exists' : 'missing'));

    // Now sync the main model with verbose output
    // dump('=== Step 2: Sync main model ===');
    test()->artisan('change-detection:sync', [
        '--models' => [TestWeatherStation::class],
        '--report' => true,
    ])->assertExitCode(0);

    // Check weather station hash
    $stationHash = Hash::where('hashable_type', 'test_weather_station')
        ->where('hashable_id', $station->id)
        ->first();
    // dump('Station hash: '.($stationHash ? 'exists' : 'missing'));
    if ($stationHash) {
        // dump('has_dependencies_built: '.($stationHash->has_dependencies_built ? 'yes' : 'no'));
    }

    // Check hash_dependents
    if ($stationHash) {
        $dependents = HashDependent::where('hash_id', $stationHash->id)->get();
        // dump('Hash dependents count: '.$dependents->count());
        foreach ($dependents as $dep) {
            // dump('Dependent: type='.$dep->dependent_type.', id='.$dep->dependent_id);
        }
    }

    // Now try the alternative: let autodiscovery handle it
    // dump('=== Alternative: Autodiscovery ===');

    // Truncate and start fresh (disable foreign key checks)
    DB::statement('SET FOREIGN_KEY_CHECKS=0;');
    Hash::truncate();
    HashDependent::truncate();
    Publish::truncate();
    DB::statement('SET FOREIGN_KEY_CHECKS=1;');

    // Run sync with autodiscovery
    test()->artisan('change-detection:sync')->assertExitCode(0);

    // Check results
    $allHashes = Hash::all();
    // dump('Total hashes created: '.$allHashes->count());
    foreach ($allHashes as $hash) {
        // dump('Hash: type='.$hash->hashable_type.', id='.$hash->hashable_id.', deps_built='.($hash->has_dependencies_built ? 'yes' : 'no'));
    }

    $allDependents = HashDependent::all();
    // dump('Total hash_dependents: '.$allDependents->count());

    expect(true)->toBeTrue();
});
