<?php

use Ameax\LaravelChangeDetection\Models\Hash;
use Ameax\LaravelChangeDetection\Models\Publisher;
use Ameax\LaravelChangeDetection\Tests\Models\TestAnimal;

// ===== ANIMAL-SPECIFIC SYNC FUNCTIONS =====

function runAnimalSync(array $options = []): void
{
    runSyncForModel(TestAnimal::class, $options);
}

// ===== ANIMAL-SPECIFIC HASH FUNCTIONS =====
// Note: Use expectActiveHashCount() and expectTotalHashCount() from HashSyncHelpers.php

function getAnimalHash(int $animalId): ?Hash
{
    return getHashForModel('test_animal', $animalId);
}

function expectAnimalHashExists(int $animalId): void
{
    expectHashExists('test_animal', $animalId);
}

function expectAnimalHashNotExists(int $animalId): void
{
    expectHashNotExists('test_animal', $animalId);
}

function expectAnimalHashActive(int $animalId): void
{
    expectHashActive('test_animal', $animalId);
}

function expectAnimalHashSoftDeleted(int $animalId): void
{
    expectHashSoftDeleted('test_animal', $animalId);
}

// ===== ANIMAL-SPECIFIC PUBLISHER FUNCTIONS =====

function createAnimalPublisher(array $overrides = []): Publisher
{
    return createPublisherForModel('test_animal', 'Test Animal Log Publisher', $overrides);
}

// ===== ANIMAL DATA MANIPULATION =====

function updateAnimalWeight(int $id, float $newWeight): TestAnimal
{
    $animal = TestAnimal::find($id);
    $animal->weight = $newWeight;
    $animal->save();

    return $animal;
}

function updateAnimalAttribute(int $id, string $attribute, $value): TestAnimal
{
    $animal = TestAnimal::find($id);
    $animal->$attribute = $value;
    $animal->save();

    return $animal;
}

function makeAnimalHeavy(int $id, float $weight = 4.5): TestAnimal
{
    return updateAnimalWeight($id, $weight);
}

function makeAnimalLight(int $id, float $weight = 2.0): TestAnimal
{
    return updateAnimalWeight($id, $weight);
}

// ===== ANIMAL TEST DATA SETUP =====

function setupStandardAnimals(): array
{
    return TestAnimal::withoutEvents(function () {
        return [
            'cat' => TestAnimal::create([
                'type' => 'Cat',
                'birthday' => 2020,
                'group' => 1,
                'features' => ['color' => 'white'],
                'weight' => 2.5, // Light < 3kg
            ]),
            'dog' => TestAnimal::create([
                'type' => 'Dog',
                'birthday' => 2021,
                'group' => 2,
                'features' => ['color' => 'brown'],
                'weight' => 4.2, // Heavy > 3kg
            ]),
            'horse' => TestAnimal::create([
                'type' => 'Horse',
                'birthday' => 2019,
                'group' => 3,
                'features' => ['color' => 'black'],
                'weight' => 150.0, // Heavy > 3kg
            ]),
            'rabbit' => TestAnimal::create([
                'type' => 'Rabbit',
                'birthday' => 2022,
                'group' => 4,
                'features' => ['color' => 'gray'],
                'weight' => 1.8, // Light < 3kg
            ]),
        ];
    });
}

function setupHeavyAnimals(int $count = 3): array
{
    return TestAnimal::withoutEvents(function () use ($count) {
        $animals = [];
        for ($i = 1; $i <= $count; $i++) {
            $animals["heavy_{$i}"] = TestAnimal::create([
                'type' => "Heavy Animal {$i}",
                'birthday' => 2020 + $i,
                'group' => $i,
                'features' => ['category' => 'heavy'],
                'weight' => 4.0 + $i, // All > 3kg
            ]);
        }
        return $animals;
    });
}

function setupLightAnimals(int $count = 3): array
{
    return TestAnimal::withoutEvents(function () use ($count) {
        $animals = [];
        for ($i = 1; $i <= $count; $i++) {
            $animals["light_{$i}"] = TestAnimal::create([
                'type' => "Light Animal {$i}",
                'birthday' => 2020 + $i,
                'group' => $i,
                'features' => ['category' => 'light'],
                'weight' => 1.0 + ($i * 0.5), // All < 3kg
            ]);
        }
        return $animals;
    });
}

function setupMixedWeightAnimals(): array
{
    $heavy = setupHeavyAnimals(2);
    $light = setupLightAnimals(2);

    return array_merge($heavy, $light);
}

// ===== ANIMAL-SPECIFIC TEST SCENARIOS =====

function simulateWeightGainScenario(int $animalId, float $startWeight, float $endWeight, int $steps = 3): array
{
    $weightIncrements = ($endWeight - $startWeight) / $steps;
    $hashes = [];

    updateAnimalWeight($animalId, $startWeight);
    runAnimalSync();
    $hashes[] = getAnimalHash($animalId)?->attribute_hash;

    for ($i = 1; $i <= $steps; $i++) {
        $currentWeight = $startWeight + ($weightIncrements * $i);
        updateAnimalWeight($animalId, $currentWeight);
        runAnimalSync();
        $hashes[] = getAnimalHash($animalId)?->attribute_hash;
    }

    return array_filter($hashes); // Remove null values
}

function simulateAnimalLifecycle(int $animalId): array
{
    $lifecycle = [];

    // Born light
    updateAnimalWeight($animalId, 1.5);
    runAnimalSync();
    $lifecycle['birth'] = getAnimalHash($animalId);

    // Grows heavy
    updateAnimalWeight($animalId, 5.0);
    runAnimalSync();
    $lifecycle['adult'] = getAnimalHash($animalId);

    // Ages and becomes light again (theoretical scenario)
    updateAnimalWeight($animalId, 2.8);
    runAnimalSync();
    $lifecycle['elderly'] = getAnimalHash($animalId);

    return $lifecycle;
}