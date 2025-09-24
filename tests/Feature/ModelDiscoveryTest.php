<?php

use Ameax\LaravelChangeDetection\Helpers\ModelDiscoveryHelper;
use Ameax\LaravelChangeDetection\Tests\Models\TestWeatherStation;
use Ameax\LaravelChangeDetection\Tests\Models\TestWindvane;
use Ameax\LaravelChangeDetection\Tests\Models\TestAnemometer;
use Ameax\LaravelChangeDetection\Tests\Models\TestCar;
use Illuminate\Database\Eloquent\Relations\Relation;

beforeEach(function () {
    // Register morph map for test models
    Relation::morphMap([
        'test_weather_station' => TestWeatherStation::class,
        'test_windvane' => TestWindvane::class,
        'test_anemometer' => TestAnemometer::class,
    ]);
});

it('can discover dependency models from composite dependencies', function () {
    $station = new TestWeatherStation();

    // Get the composite dependencies (relation names)
    $dependencies = $station->getHashCompositeDependencies();
    expect($dependencies)->toBe(['windvanes', 'anemometers']);

    // Discover the actual model classes from the relations
    $discoveredModels = [];

    foreach ($dependencies as $relationName) {
        // Get the relation instance
        $relation = $station->$relationName();

        // Get the related model class
        $relatedModel = get_class($relation->getRelated());

        $discoveredModels[$relationName] = $relatedModel;
    }

    // Verify we found the correct model classes
    expect($discoveredModels)->toBe([
        'windvanes' => 'Ameax\LaravelChangeDetection\Tests\Models\TestWindvane',
        'anemometers' => 'Ameax\LaravelChangeDetection\Tests\Models\TestAnemometer',
    ]);

    // Also test that we can get the morph type from the model
    $windvaneInstance = $station->windvanes()->getRelated();
    $anemometerInstance = $station->anemometers()->getRelated();

    // These would be the morph types if they implement getMorphClass()
    expect($windvaneInstance->getMorphClass())->toBe('test_windvane');
    expect($anemometerInstance->getMorphClass())->toBe('test_anemometer');
});

it('can discover all models from publisher model_type', function () {
    // Simulate what we have in the Publisher table
    $publisherModelType = 'test_weather_station';

    // Step 1: Resolve the main model class from morph map
    $morphMap = Relation::morphMap();
    $mainModelClass = $morphMap[$publisherModelType] ?? $publisherModelType;

    expect($mainModelClass)->toBe(TestWeatherStation::class);

    // Step 2: Get an instance to discover dependencies
    $mainModel = new $mainModelClass();

    // Step 3: Collect all model classes that need to be synced
    $modelsToSync = [$mainModelClass];

    // Step 4: Get composite dependencies and discover their model classes
    $dependencies = $mainModel->getHashCompositeDependencies();

    foreach ($dependencies as $relationName) {
        if (method_exists($mainModel, $relationName)) {
            $relation = $mainModel->$relationName();
            $relatedModelClass = get_class($relation->getRelated());
            $modelsToSync[] = $relatedModelClass;
        }
    }

    // Verify we found all necessary models for syncing
    expect($modelsToSync)->toBe([
        TestWeatherStation::class,
        TestWindvane::class,
        TestAnemometer::class,
    ]);

    // These are all the models that need to be processed by sync command
    expect($modelsToSync)->toHaveCount(3);
});

describe('ModelDiscoveryHelper', function () {
    it('gets dependency models from a model instance', function () {
        $station = new TestWeatherStation();

        $dependencies = ModelDiscoveryHelper::getDependencyModelsFromModel($station);

        expect($dependencies)->toBe([
            'windvanes' => TestWindvane::class,
            'anemometers' => TestAnemometer::class,
        ]);
    });

    it('gets model class from morph name', function () {
        // Test with registered morph name
        $modelClass = ModelDiscoveryHelper::getModelClassFromMorphName('test_weather_station');
        expect($modelClass)->toBe(TestWeatherStation::class);

        // Test with unregistered but valid class name
        $modelClass = ModelDiscoveryHelper::getModelClassFromMorphName(TestCar::class);
        expect($modelClass)->toBe(TestCar::class);

        // Test with invalid name
        $modelClass = ModelDiscoveryHelper::getModelClassFromMorphName('non_existent_model');
        expect($modelClass)->toBeNull();
    });

    it('gets model class from relation name', function () {
        $station = new TestWeatherStation();

        // Test valid relation
        $modelClass = ModelDiscoveryHelper::getModelClassFromRelation($station, 'windvanes');
        expect($modelClass)->toBe(TestWindvane::class);

        // Test another valid relation
        $modelClass = ModelDiscoveryHelper::getModelClassFromRelation($station, 'anemometers');
        expect($modelClass)->toBe(TestAnemometer::class);

        // Test invalid relation
        $modelClass = ModelDiscoveryHelper::getModelClassFromRelation($station, 'non_existent_relation');
        expect($modelClass)->toBeNull();
    });

    it('gets dependency models from morph name', function () {
        $dependencies = ModelDiscoveryHelper::getDependencyModelsFromMorphName('test_weather_station');

        expect($dependencies)->toBe([
            'windvanes' => TestWindvane::class,
            'anemometers' => TestAnemometer::class,
        ]);

        // Test with model that has no dependencies
        Relation::morphMap(['test_car' => TestCar::class]);
        $dependencies = ModelDiscoveryHelper::getDependencyModelsFromMorphName('test_car');
        expect($dependencies)->toBe([]);
    });

    it('gets all models for sync from morph name', function () {
        $models = ModelDiscoveryHelper::getAllModelsForSync('test_weather_station');

        expect($models)->toBe([
            TestWeatherStation::class,
            TestWindvane::class,
            TestAnemometer::class,
        ]);

        // Test with model that has no dependencies
        Relation::morphMap(['test_car' => TestCar::class]);
        $models = ModelDiscoveryHelper::getAllModelsForSync('test_car');
        expect($models)->toBe([TestCar::class]);
    });

    it('checks if model is hashable', function () {
        expect(ModelDiscoveryHelper::isHashable(TestWeatherStation::class))->toBeTrue();
        expect(ModelDiscoveryHelper::isHashable(TestWindvane::class))->toBeTrue();
        expect(ModelDiscoveryHelper::isHashable('NonExistentClass'))->toBeFalse();
    });

    it('filters hashable models from array', function () {
        $models = [
            TestWeatherStation::class,
            TestWindvane::class,
            TestAnemometer::class,
            'NonExistentClass',
        ];

        $hashableModels = ModelDiscoveryHelper::filterHashableModels($models);

        expect($hashableModels)->toBe([
            TestWeatherStation::class,
            TestWindvane::class,
            TestAnemometer::class,
        ]);
    });

    it('handles empty composite dependencies correctly', function () {
        $car = new TestCar();

        $dependencies = ModelDiscoveryHelper::getDependencyModelsFromModel($car);
        expect($dependencies)->toBe([]);
    });
});