<?php

use Ameax\LaravelChangeDetection\Models\Hash;
use Ameax\LaravelChangeDetection\Tests\Models\TestCar;

beforeEach(function () {
    // Register TestCar in the morph map for cleaner database entries
    \Illuminate\Database\Eloquent\Relations\Relation::morphMap([
        'test_car' => TestCar::class,
    ]);
});

it('creates hashes for new cars when sync command is executed', function () {
    // Create 5 cars
    $cars = [];
    for ($i = 1; $i <= 5; $i++) {
        $cars[] = TestCar::create([
            'model' => "Model {$i}",
            'year' => 2020 + $i,
            'price' => 20000 + ($i * 5000),
            'is_electric' => $i % 2 === 0,
            'features' => ['color' => 'red', 'seats' => 5],
        ]);
    }

    // Verify no hashes exist yet
    $hashCount = Hash::where('hashable_type', 'test_car')->count();
    expect($hashCount)->toBe(0);

    // Execute the sync command
    $this->artisan('change-detection:sync', [
        '--models' => [TestCar::class],
    ])->assertExitCode(0);

    // Verify hashes were created for all cars
    $hashCount = Hash::where('hashable_type', 'test_car')->count();
    expect($hashCount)->toBe(5);

    // Verify each car has a hash with correct data
    foreach ($cars as $car) {
        $hash = Hash::where('hashable_type', 'test_car')
            ->where('hashable_id', $car->id)
            ->first();

        expect($hash)->not->toBeNull();
        expect($hash->attribute_hash)->not->toBeNull();
        expect($hash->composite_hash)->not->toBeNull();
        // Since TestCar has no dependencies, attribute_hash should equal composite_hash
        expect($hash->attribute_hash)->toBe($hash->composite_hash);
    }
});

it('detects and updates changes in existing cars', function () {
    // Create cars with hashes
    $car1 = TestCar::create([
        'model' => 'Tesla Model S',
        'year' => 2023,
        'price' => 80000,
        'is_electric' => true,
        'features' => ['autopilot' => true],
    ]);

    $car2 = TestCar::create([
        'model' => 'BMW 330i',
        'year' => 2022,
        'price' => 45000,
        'is_electric' => false,
        'features' => ['sport_package' => true],
    ]);

    // First run sync to create initial hashes
    $this->artisan('change-detection:sync', [
        '--models' => [TestCar::class],
    ])->assertExitCode(0);

    // Store original hashes
    $originalHash1 = $car1->getCurrentHash()->attribute_hash;
    $originalHash2 = $car2->getCurrentHash()->attribute_hash;

    // Modify cars
    $car1->update(['price' => 75000]);
    $car2->update(['year' => 2023]);

    // Execute sync command
    $this->artisan('change-detection:sync', [
        '--models' => [TestCar::class],
    ])
        ->expectsOutputToContain('2 changes detected')
        ->expectsOutputToContain('Updated 2 hash records')
        ->assertExitCode(0);

    // Verify hashes were updated
    $newHash1 = Hash::where('hashable_type', 'test_car')
        ->where('hashable_id', $car1->id)
        ->first();

    $newHash2 = Hash::where('hashable_type', 'test_car')
        ->where('hashable_id', $car2->id)
        ->first();

    expect($newHash1->attribute_hash)->not->toBe($originalHash1);
    expect($newHash2->attribute_hash)->not->toBe($originalHash2);
});

it('shows dry run results without making changes', function () {
    // Create cars
    for ($i = 1; $i <= 3; $i++) {
        TestCar::create([
            'model' => "Car {$i}",
            'year' => 2020 + $i,
            'price' => 30000 + ($i * 1000),
            'is_electric' => false,
            'features' => [],
        ]);
    }

    // Verify no hashes exist
    $initialHashCount = Hash::where('hashable_type', 'test_car')->count();
    expect($initialHashCount)->toBe(0);

    // Execute sync command in dry-run mode
    $this->artisan('change-detection:sync', [
        '--models' => [TestCar::class],
        '--dry-run' => true,
    ])
        ->expectsOutputToContain('DRY RUN MODE')
        ->expectsOutputToContain('Changes detected: 3')
        ->assertExitCode(0);

    // Verify no hashes were created
    $finalHashCount = Hash::where('hashable_type', 'test_car')->count();
    expect($finalHashCount)->toBe(0);
});

it('handles limit option correctly', function () {
    // Create 10 cars
    for ($i = 1; $i <= 10; $i++) {
        TestCar::create([
            'model' => "Limited Car {$i}",
            'year' => 2020,
            'price' => 25000,
            'is_electric' => false,
            'features' => null,
        ]);
    }

    // Verify no hashes exist
    $initialHashCount = Hash::where('hashable_type', 'test_car')->count();
    expect($initialHashCount)->toBe(0);

    // Execute sync command with limit
    $this->artisan('change-detection:sync', [
        '--models' => [TestCar::class],
        '--limit' => 5,
    ])->assertExitCode(0);

    // Verify only 5 hashes were created
    $hashCount = Hash::where('hashable_type', 'test_car')->count();
    expect($hashCount)->toBe(5);
});

it('shows detailed report when requested', function () {
    // Create cars
    for ($i = 1; $i <= 3; $i++) {
        TestCar::create([
            'model' => "Report Car {$i}",
            'year' => 2024,
            'price' => 35000,
            'is_electric' => true,
            'features' => ['test' => true],
        ]);
    }

    // Execute sync command with report flag
    $this->artisan('change-detection:sync', [
        '--models' => [TestCar::class],
        '--report' => true,
    ])
        ->expectsOutputToContain('Detailed Report')
        ->expectsOutputToContain('TestCar')
        ->assertExitCode(0);

    // Verify hashes were created
    $hashCount = Hash::where('hashable_type', 'test_car')->count();
    expect($hashCount)->toBe(3);
});
