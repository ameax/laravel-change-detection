<?php

use Ameax\LaravelChangeDetection\Models\Hash;
use Ameax\LaravelChangeDetection\Models\Publish;
use Ameax\LaravelChangeDetection\Models\Publisher;
use Ameax\LaravelChangeDetection\Publishers\LogPublisher;
use Ameax\LaravelChangeDetection\Tests\Models\TestAnimal;

function createAnimalPublisher(array $overrides = []): Publisher
{
    return Publisher::create(array_merge([
        'name' => 'Test Animal Log Publisher',
        'model_type' => 'test_animal',
        'publisher_class' => LogPublisher::class,
        'status' => 'active',
        'config' => [
            'log_channel' => 'stack',
            'log_level' => 'info',
            'include_hash_data' => true,
        ],
    ], $overrides));
}

function runSync(array $options = []): void
{
    test()->artisan('change-detection:sync', array_merge([
        '--models' => [TestAnimal::class],
    ], $options))->assertExitCode(0);
}

function expectActiveHashCount(int $count): void
{
    expect(Hash::where('hashable_type', 'test_animal')->whereNull('deleted_at')->count())
        ->toBe($count);
}

function expectTotalHashCount(int $count): void
{
    expect(Hash::where('hashable_type', 'test_animal')->count())
        ->toBe($count);
}

function expectPublishCount(Publisher $publisher, int $count): void
{
    expect(Publish::where('publisher_id', $publisher->id)->count())->toBe($count);
}

function getAnimalHash(int $animalId): ?Hash
{
    return Hash::where('hashable_type', 'test_animal')
        ->where('hashable_id', $animalId)
        ->first();
}

function updateAnimalWeight(int $id, float $newWeight): TestAnimal
{
    $animal = TestAnimal::find($id);
    $animal->weight = $newWeight;
    $animal->save();

    return $animal;
}

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
