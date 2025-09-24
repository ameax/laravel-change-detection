<?php

use Ameax\LaravelChangeDetection\Models\Hash;
use Ameax\LaravelChangeDetection\Models\Publish;
use Ameax\LaravelChangeDetection\Models\Publisher;
use Ameax\LaravelChangeDetection\Publishers\LogPublisher;

// ===== SYNC FUNCTIONS =====

function runSyncForModel(string $modelClass, array $options = []): void
{
    test()->artisan('change-detection:sync', array_merge([
        '--models' => [$modelClass],
    ], $options))->assertExitCode(0);
}

function runSyncAutoDiscover(array $options = []): void
{
    test()->artisan('change-detection:sync', $options)->assertExitCode(0);
}

function runSyncForModels(array $modelClasses, array $options = []): void
{
    test()->artisan('change-detection:sync', array_merge([
        '--models' => $modelClasses,
    ], $options))->assertExitCode(0);
}

function runSyncWithPurge(string $modelClass, array $additionalOptions = []): void
{
    runSyncForModel($modelClass, array_merge(['--purge' => true], $additionalOptions));
}

function runSyncAndExpectCount(string $modelClass, string $morphType, int $expectedCount): void
{
    runSyncForModel($modelClass);
    expectActiveHashCountForType($morphType, $expectedCount);
}

// ===== HASH ASSERTION FUNCTIONS =====

function expectActiveHashCountForType(string $morphType, int $count): void
{
    expect(Hash::where('hashable_type', $morphType)->whereNull('deleted_at')->count())
        ->toBe($count);
}

function expectTotalHashCountForType(string $morphType, int $count): void
{
    expect(Hash::where('hashable_type', $morphType)->count())
        ->toBe($count);
}

// Convenience function - assumes 'test_animal' morph type for backward compatibility
function expectActiveHashCount(int $count): void
{
    expectActiveHashCountForType('test_animal', $count);
}

function expectTotalHashCount(int $count): void
{
    expectTotalHashCountForType('test_animal', $count);
}

function getHashForModel(string $morphType, int $modelId): ?Hash
{
    return Hash::where('hashable_type', $morphType)
        ->where('hashable_id', $modelId)
        ->first();
}

function expectHashExists(string $morphType, int $modelId): void
{
    expect(getHashForModel($morphType, $modelId))->not->toBeNull();
}

function expectHashNotExists(string $morphType, int $modelId): void
{
    expect(getHashForModel($morphType, $modelId))->toBeNull();
}

function expectHashSoftDeleted(string $morphType, int $modelId): void
{
    $hash = getHashForModel($morphType, $modelId);
    expect($hash)->not->toBeNull();
    expect($hash->deleted_at)->not->toBeNull();
}

function expectHashActive(string $morphType, int $modelId): void
{
    $hash = getHashForModel($morphType, $modelId);
    expect($hash)->not->toBeNull();
    expect($hash->deleted_at)->toBeNull();
}

function expectHashChanged(string $morphType, int $modelId, string $originalHash, string $hashField = 'attribute_hash'): void
{
    $hash = getHashForModel($morphType, $modelId);
    expect($hash)->not->toBeNull();
    expect($hash->$hashField)->not->toBe($originalHash);
}

function expectHashUnchanged(string $morphType, int $modelId, string $originalHash, string $hashField = 'attribute_hash'): void
{
    $hash = getHashForModel($morphType, $modelId);
    expect($hash)->not->toBeNull();
    expect($hash->$hashField)->toBe($originalHash);
}

function getAllHashStatesForModel(string $morphType, int $modelId, string $hashField = 'composite_hash'): array
{
    $hash = getHashForModel($morphType, $modelId);

    return $hash ? [$hash->$hashField] : [];
}

// ===== PUBLISHER FUNCTIONS =====

function createPublisherForModel(string $modelType, ?string $name = null, array $overrides = []): Publisher
{
    return Publisher::create(array_merge([
        'name' => $name ?? 'Test '.ucfirst(str_replace('_', ' ', $modelType)).' Publisher',
        'model_type' => $modelType,
        'publisher_class' => LogPublisher::class,
        'status' => 'active',
        'config' => [
            'log_channel' => 'stack',
            'log_level' => 'info',
            'include_hash_data' => true,
        ],
    ], $overrides));
}

function createInactivePublisher(string $modelType, ?string $name = null): Publisher
{
    return createPublisherForModel($modelType, $name, ['status' => 'inactive']);
}

function createApiPublisher(string $modelType, string $endpoint, ?string $name = null): Publisher
{
    return createPublisherForModel($modelType, $name, [
        'config' => [
            'endpoint' => $endpoint,
            'api_key' => 'test_key',
            'format' => 'json',
        ],
    ]);
}

function expectPublishCount(Publisher $publisher, int $count): void
{
    expect(Publish::where('publisher_id', $publisher->id)->count())->toBe($count);
}

function expectPublishCountForModel(string $morphType, int $expectedCount): void
{
    $publishCount = Publish::whereHas('hash', function ($query) use ($morphType) {
        $query->where('hashable_type', $morphType);
    })->count();

    expect($publishCount)->toBe($expectedCount);
}

function expectNoPublishRecords(): void
{
    expect(Publish::count())->toBe(0);
}

// ===== COMBINED HELPER FUNCTIONS =====

function syncAndVerifyHashCreation(string $modelClass, string $morphType, int $modelId): Hash
{
    runSyncForModel($modelClass);
    expectHashActive($morphType, $modelId);

    return getHashForModel($morphType, $modelId);
}

function syncAndVerifyHashDeletion(string $modelClass, string $morphType, int $modelId, bool $purge = false): void
{
    if ($purge) {
        runSyncWithPurge($modelClass);
        expectHashNotExists($morphType, $modelId);
    } else {
        runSyncForModel($modelClass);
        expectHashSoftDeleted($morphType, $modelId);
    }
}

function verifyHashEvolution(array $hashes, int $expectedUniqueCount): void
{
    expect(count(array_unique($hashes)))->toBe($expectedUniqueCount);
}
