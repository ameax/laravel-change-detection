<?php

use Ameax\LaravelChangeDetection\Models\Hash;
use Ameax\LaravelChangeDetection\Models\Publish;
use Ameax\LaravelChangeDetection\Models\Publisher;
use Ameax\LaravelChangeDetection\Tests\Models\TestCar;

/**
 * Create a test car with specified attributes
 */
function createCar(array $attributes = []): TestCar
{
    $defaults = [
        'model' => 'Tesla Model 3',
        'year' => 2023,
        'price' => 40000,
        'is_electric' => true,
        'features' => ['autopilot' => true],
    ];

    return TestCar::create(array_merge($defaults, $attributes));
}

/**
 * Create a publisher for cars
 */
function createCarPublisher(array $attributes = []): Publisher
{
    $defaults = [
        'name' => 'Car API Publisher',
        'model_type' => 'test_car',
        'publisher_class' => 'Ameax\\LaravelChangeDetection\\Publishers\\LogPublisher',
        'config' => [
            'endpoint' => 'https://api.example.com/cars',
            'api_key' => 'test_key_123',
        ],
        'status' => 'active',
        'retry_attempts' => 3,
        'retry_delay' => 60,
    ];

    return Publisher::create(array_merge($defaults, $attributes));
}

/**
 * Create a car and sync its hash
 */
function createCarWithHash(array $attributes = []): TestCar
{
    $car = createCar($attributes);

    // Run sync to create hash
    app(\Ameax\LaravelChangeDetection\Services\BulkHashProcessor::class)
        ->updateHashesForIds(TestCar::class, [$car->id]);

    return $car->fresh();
}

/**
 * Create publish record for a car
 */
function createPublishForCar(TestCar $car, Publisher $publisher, array $attributes = []): Publish
{
    $hash = $car->getCurrentHash();

    if (!$hash) {
        throw new \Exception('Car does not have a hash. Run sync first.');
    }

    $defaults = [
        'hash_id' => $hash->id,
        'publisher_id' => $publisher->id,
        'status' => 'pending',
        'attempts' => 0,
    ];

    return Publish::create(array_merge($defaults, $attributes));
}

/**
 * Run sync command for cars
 */
function syncCars(array $options = []): void
{
    $command = 'change-detection:sync';
    $defaultOptions = [
        '--models' => [TestCar::class],
    ];

    test()->artisan($command, array_merge($defaultOptions, $options))
        ->assertExitCode(0);
}

/**
 * Assert car has a hash
 */
function assertCarHasHash(TestCar $car): Hash
{
    $hash = Hash::where('hashable_type', 'test_car')
        ->where('hashable_id', $car->id)
        ->first();

    expect($hash)->not->toBeNull();
    expect($hash->attribute_hash)->not->toBeNull();
    expect($hash->composite_hash)->not->toBeNull();

    return $hash;
}

/**
 * Assert publish record exists with expected status
 */
function assertPublishExists(TestCar $car, Publisher $publisher, string $expectedStatus = 'pending'): Publish
{
    $hash = $car->getCurrentHash();

    expect($hash)->not->toBeNull();

    $publish = Publish::where('hash_id', $hash->id)
        ->where('publisher_id', $publisher->id)
        ->first();

    expect($publish)->not->toBeNull();
    expect($publish->status)->toBe($expectedStatus);

    return $publish;
}