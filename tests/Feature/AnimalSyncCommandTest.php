<?php

use Ameax\LaravelChangeDetection\Models\Hash;
use Ameax\LaravelChangeDetection\Tests\Models\TestAnimal;

beforeEach(function () {
    // Register Testanimals in the morph map for cleaner database entries
    \Illuminate\Database\Eloquent\Relations\Relation::morphMap([
        'test_animal' => TestAnimal::class,
    ]);
});

it('scope for heavy animals with hash generator', function () {
    // Create 4 animals with different weights without triggering hash creation
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

    // Controlling if no hashes exist in start
    expect(Hash::where('hashable_type', 'test_animal')->count())->toBe(0);

    // Generate hashes only for heavy animals (weight > 3kg)
    $heavyAnimals = TestAnimal::heavyAnimals()->get();
    foreach ($heavyAnimals as $animal) {
        $animal->update();
    }

    // Controlling if only 2 animals are heavy (Dog and Horse)
    expect($heavyAnimals)->toHaveCount(2);
    expect($heavyAnimals->pluck('type')->sort()->values()->toArray())
        ->toEqual(['Dog', 'Horse']);

    // Controlling if only 2 hashes were created (for heavy animals only)
    expect(Hash::where('hashable_type', 'test_animal')->count())->toBe(2);

    // Controlling if the hashes belong to heavy animals only
    $hashedAnimalIds = Hash::where('hashable_type', 'test_animal')->pluck('hashable_id');
    $hashedAnimals = TestAnimal::whereIn('id', $hashedAnimalIds)->get();

    expect($hashedAnimals->every(fn ($animal) => $animal->weight > 3))->toBeTrue();
    expect($hashedAnimals->pluck('type')->sort()->values()->toArray())->toEqual(['Dog', 'Horse']);
});
