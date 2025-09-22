<?php

use Ameax\LaravelChangeDetection\Models\Hash;
use Ameax\LaravelChangeDetection\Tests\Models\TestAnimal;

beforeEach(function () {
    // Register Testanimals in the morph map for cleaner database entries
    \Illuminate\Database\Eloquent\Relations\Relation::morphMap([
        'test_animal' => TestAnimal::class,
    ]);
});


it ('filter animals by type', function() {
    // create 3 animals of different types
    TestAnimal::withoutEvents(function () {
        TestAnimal::create([
            'type' => "Cat",
            'birthday' => 2024,
            'group' => 3,
            'features' => ['color' => 'white'],
        ]);
        TestAnimal::create([
            'type' => "Dog",
            'birthday' => 2024,
            'group' => 2,
            'features' => ['color' => 'white'],
        ]);
        TestAnimal::create([
            'type' => "Cat",
            'birthday' => 2020,
            'group' => 3,
            'features' => ['color' => 'white'],
        ]);
        $dogs = TestAnimal::typeFilter('Cat')->get();
        expect($dogs->first()->type)->toBe('Cat')
            ->and($dogs)->toHaveCount(2);
    });
});
