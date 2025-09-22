<?php

use Ameax\LaravelChangeDetection\Models\Hash;
use Ameax\LaravelChangeDetection\Tests\Models\TestAnimal;
use Ameax\LaravelChangeDetection\Tests\Models\TestCar;

beforeEach(function () {
    // Register Testanimals in the morph map for cleaner database entries
    \Illuminate\Database\Eloquent\Relations\Relation::morphMap([
        'test_animal' => TestAnimal::class,
    ]);

    TestAnimal::withoutEvents(function () {
        TestAnimal::create([
            'type' => 'Cat',
            'birthday' => 2020,
            'group' => 1,
            'features' => ['color' => 'white'],
            'weight' => 2.5, // Light animal (< 3kg)
        ]);
        TestAnimal::create([
            'type' => 'Dog',
            'birthday' => 2021,
            'group' => 2,
            'features' => ['color' => 'brown'],
            'weight' => 4.2, // Heavy animal (> 3kg)
        ]);
        TestAnimal::create([
            'type' => 'Horse',
            'birthday' => 2019,
            'group' => 3,
            'features' => ['color' => 'black'],
            'weight' => 150.0, // Heavy animal (> 3kg)
        ]);
        TestAnimal::create([
            'type' => 'Rabbit',
            'birthday' => 2022,
            'group' => 4,
            'features' => ['color' => 'gray'],
            'weight' => 1.8, // Light animal (< 3kg)
        ]);
    });
});

it('scope for heavy animals with hash bulk generator', function () {
    // Controlling if no hashes exist in start
    expect(Hash::where('hashable_type', 'test_animal')->count())->toBe(0);

    // Execute the sync command
    $this->artisan('change-detection:sync', [
        '--models' => [TestAnimal::class],
    ])->assertExitCode(0);

    expect(Hash::where('hashable_type', 'test_animal')->count())->toBe(2);
});

it('scope for heavy animals with hash bulk generator and publisher', function () {
    // Create a LogPublisher for TestAnimal
    $publisher = \Ameax\LaravelChangeDetection\Models\Publisher::create([
        'name' => 'Test Animal Log Publisher',
        'model_type' => 'test_animal',
        'publisher_class' => \Ameax\LaravelChangeDetection\Publishers\LogPublisher::class,
        'status' => 'active',
        'config' => [
            'log_channel' => 'stack',
            'log_level' => 'info',
            'include_hash_data' => true,
        ],
    ]);

    // Verify publisher was created
    expect($publisher)->toBeInstanceOf(\Ameax\LaravelChangeDetection\Models\Publisher::class);
    expect($publisher->model_type)->toBe('test_animal');
    expect($publisher->isActive())->toBeTrue();

    // Controlling if no hashes exist in start
    expect(Hash::where('hashable_type', 'test_animal')->count())->toBe(0);

    // Execute the sync command
    $this->artisan('change-detection:sync', [
        '--models' => [TestAnimal::class],
    ])->assertExitCode(0);

    // Verify hashes were created for heavy animals only (weight > 3kg)
    expect(Hash::where('hashable_type', 'test_animal')->count())->toBe(2);

    // Verify publish records were created
    expect(\Ameax\LaravelChangeDetection\Models\Publish::where('publisher_id', $publisher->id)->count())->toBe(2);
});


it('scope for heavy animals with hash bulk generator and publisher with changes', function () {
    // Create a LogPublisher for TestAnimal
    $publisher = \Ameax\LaravelChangeDetection\Models\Publisher::create([
        'name' => 'Test Animal Log Publisher',
        'model_type' => 'test_animal',
        'publisher_class' => \Ameax\LaravelChangeDetection\Publishers\LogPublisher::class,
        'status' => 'active',
        'config' => [
            'log_channel' => 'stack',
            'log_level' => 'info',
            'include_hash_data' => true,
        ],
    ]);

    // Verify publisher was created
    expect($publisher)->toBeInstanceOf(\Ameax\LaravelChangeDetection\Models\Publisher::class);
    expect($publisher->model_type)->toBe('test_animal');
    expect($publisher->isActive())->toBeTrue();

    // Controlling if no hashes exist in start
    expect(Hash::where('hashable_type', 'test_animal')->count())->toBe(0);

    // Execute the sync command
    $this->artisan('change-detection:sync', [
        '--models' => [TestAnimal::class],
    ])->assertExitCode(0);

    // Verify hashes were created for heavy animals only (weight > 3kg)
    expect(Hash::where('hashable_type', 'test_animal')->count())->toBe(2);

    // Verify publish records were created
    expect(\Ameax\LaravelChangeDetection\Models\Publish::where('publisher_id', $publisher->id)->count())->toBe(2);

    // Update one of the animals (Dog with id=2)
    $animal = TestAnimal::find(2);
    expect($animal)->not->toBeNull();
    expect($animal->type)->toBe('Dog');
    expect($animal->weight)->toBe(4.2);

    // Get the original hash before update
    $originalHash = Hash::where('hashable_type', 'test_animal')
        ->where('hashable_id', 2)
        ->first();
    expect($originalHash)->not->toBeNull();
    $originalAttributeHash = $originalHash->attribute_hash;
    $originalCompositeHash = $originalHash->composite_hash;

    // Update the animal's weight
    $animal->weight = 5.5; // Still heavy (> 3kg)
    $animal->save();

    // Run sync command again to detect changes
    $this->artisan('change-detection:sync', [
        '--models' => [TestAnimal::class],
    ])->assertExitCode(0);

    // Get the new hash after update
    $newHash = Hash::where('hashable_type', 'test_animal')
        ->where('hashable_id', 2)
        ->first();
    expect($newHash)->not->toBeNull();

    // Verify the hash has changed
    expect($newHash->attribute_hash)->not->toBe($originalAttributeHash);
    expect($newHash->composite_hash)->not->toBe($originalCompositeHash);

    // Verify we still have 2 publish records (publish records are per hash_id, not per update)
    // The sync command updates existing hashes in place, so the same publish record is reused
    expect(\Ameax\LaravelChangeDetection\Models\Publish::where('publisher_id', $publisher->id)->count())->toBe(2);
})->only();


it('scope for heavy animals with hash bulk generator and publisher with changes - record leaves scope', function () {
    // Create a LogPublisher for TestAnimal
    $publisher = \Ameax\LaravelChangeDetection\Models\Publisher::create([
        'name' => 'Test Animal Log Publisher',
        'model_type' => 'test_animal',
        'publisher_class' => \Ameax\LaravelChangeDetection\Publishers\LogPublisher::class,
        'status' => 'active',
        'config' => [
            'log_channel' => 'stack',
            'log_level' => 'info',
            'include_hash_data' => true,
        ],
    ]);

    // Verify publisher was created
    expect($publisher)->toBeInstanceOf(\Ameax\LaravelChangeDetection\Models\Publisher::class);
    expect($publisher->model_type)->toBe('test_animal');
    expect($publisher->isActive())->toBeTrue();

    // Controlling if no hashes exist in start
    expect(Hash::where('hashable_type', 'test_animal')->count())->toBe(0);

    // Execute the sync command
    $this->artisan('change-detection:sync', [
        '--models' => [TestAnimal::class],
    ])->assertExitCode(0);

    // Verify hashes were created for heavy animals only (weight > 3kg)
    expect(Hash::where('hashable_type', 'test_animal')->count())->toBe(2);

    // Verify publish records were created
    expect(\Ameax\LaravelChangeDetection\Models\Publish::where('publisher_id', $publisher->id)->count())->toBe(2);

    // Update one of the animals (Dog with id=2)
    $animal = TestAnimal::find(2);
    expect($animal)->not->toBeNull();
    expect($animal->type)->toBe('Dog');
    expect($animal->weight)->toBe(4.2);

    // Get the original hash before update
    $originalHash = Hash::where('hashable_type', 'test_animal')
                        ->where('hashable_id', 2)
                        ->first();
    expect($originalHash)->not->toBeNull();

    // Update the animal's weight to make it light (leaves the scope)
    $animal->weight = 1.9; // Now light (< 3kg) - leaves the scope
    $animal->save();

    // Run sync command again to detect that the record left the scope
    $this->artisan('change-detection:sync', [
        '--models' => [TestAnimal::class],
    ])->assertExitCode(0);

    // The hash should now be soft-deleted since the record left the scope
    $deletedHash = Hash::where('hashable_type', 'test_animal')
                       ->where('hashable_id', 2)
                       ->first();
    expect($deletedHash)->not->toBeNull();
    expect($deletedHash->deleted_at)->not->toBeNull();

    // Verify we now have only 1 active hash (for the Horse which is still heavy)
    expect(Hash::where('hashable_type', 'test_animal')->whereNull('deleted_at')->count())->toBe(1);

    // Verify the remaining hash is for the Horse (id=3)
    $remainingHash = Hash::where('hashable_type', 'test_animal')->whereNull('deleted_at')->first();
    expect($remainingHash->hashable_id)->toBe(3);

    // Publish records should remain unchanged at 2 (they don't get deleted when hash is soft-deleted)
    expect(\Ameax\LaravelChangeDetection\Models\Publish::where('publisher_id', $publisher->id)->count())->toBe(2);
})->only();



it('scope for heavy animals with hash bulk generator and publisher with changes - record leaves scope with purge', function () {
    // Create a LogPublisher for TestAnimal
    $publisher = \Ameax\LaravelChangeDetection\Models\Publisher::create([
        'name' => 'Test Animal Log Publisher',
        'model_type' => 'test_animal',
        'publisher_class' => \Ameax\LaravelChangeDetection\Publishers\LogPublisher::class,
        'status' => 'active',
        'config' => [
            'log_channel' => 'stack',
            'log_level' => 'info',
            'include_hash_data' => true,
        ],
    ]);

    // Verify publisher was created
    expect($publisher)->toBeInstanceOf(\Ameax\LaravelChangeDetection\Models\Publisher::class);
    expect($publisher->model_type)->toBe('test_animal');
    expect($publisher->isActive())->toBeTrue();

    // Controlling if no hashes exist in start
    expect(Hash::where('hashable_type', 'test_animal')->count())->toBe(0);

    // Execute the sync command
    $this->artisan('change-detection:sync', [
        '--models' => [TestAnimal::class],
    ])->assertExitCode(0);

    // Verify hashes were created for heavy animals only (weight > 3kg)
    expect(Hash::where('hashable_type', 'test_animal')->count())->toBe(2);

    // Verify publish records were created
    expect(\Ameax\LaravelChangeDetection\Models\Publish::where('publisher_id', $publisher->id)->count())->toBe(2);

    // Update one of the animals (Dog with id=2)
    $animal = TestAnimal::find(2);
    expect($animal)->not->toBeNull();
    expect($animal->type)->toBe('Dog');
    expect($animal->weight)->toBe(4.2);

    // Get the original hash before update
    $originalHash = Hash::where('hashable_type', 'test_animal')
                        ->where('hashable_id', 2)
                        ->first();
    expect($originalHash)->not->toBeNull();

    // Update the animal's weight to make it light (leaves the scope)
    $animal->weight = 1.9; // Now light (< 3kg) - leaves the scope
    $animal->save();

    // Run sync command again to detect that the record left the scope
    $this->artisan('change-detection:sync', [
        '--models' => [TestAnimal::class],
        '--purge' => true,
    ])->assertExitCode(0);

    // The hash should now be soft-deleted since the record left the scope
    $deletedHash = Hash::where('hashable_type', 'test_animal')
                       ->where('hashable_id', 2)
                       ->first();
    expect($deletedHash)->toBeNull();

    // Verify the remaining hash is for the Horse (id=3)
    $remainingHash = Hash::where('hashable_type', 'test_animal')->whereNull('deleted_at')->first();
    expect($remainingHash->hashable_id)->toBe(3);

    // Publish records should remain unchanged at 2 (they don't get deleted when hash is soft-deleted)
    expect(\Ameax\LaravelChangeDetection\Models\Publish::where('publisher_id', $publisher->id)->count())->toBe(1);
})->only();
