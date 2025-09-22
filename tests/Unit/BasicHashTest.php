<?php

use Ameax\LaravelChangeDetection\Models\Hash;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('can create a basic hash record', function () {
    $hash = Hash::create([
        'hashable_type' => 'test_model',
        'hashable_id' => 1,
        'attribute_hash' => 'abc123',
        'composite_hash' => 'def456',
    ]);

    expect($hash)->not()->toBeNull()
        ->and($hash->hashable_type)->toBe('test_model')
        ->and($hash->hashable_id)->toBe(1)
        ->and($hash->attribute_hash)->toBe('abc123')
        ->and($hash->composite_hash)->toBe('def456');
});

it('can mark hash as deleted', function () {
    $hash = Hash::create([
        'hashable_type' => 'test_model',
        'hashable_id' => 1,
        'attribute_hash' => 'abc123',
        'composite_hash' => 'def456',
    ]);

    $hash->markAsDeleted();

    expect($hash->isDeleted())->toBeTrue()
        ->and($hash->deleted_at)->not()->toBeNull();
});

it('can use active scope', function () {
    Hash::create([
        'hashable_type' => 'test_model',
        'hashable_id' => 1,
        'attribute_hash' => 'abc123',
        'composite_hash' => 'def456',
    ]);

    $deletedHash = Hash::create([
        'hashable_type' => 'test_model',
        'hashable_id' => 2,
        'attribute_hash' => 'xyz789',
        'composite_hash' => 'uvw123',
    ]);
    $deletedHash->markAsDeleted();

    $activeHashes = Hash::active()->get();

    expect($activeHashes)->toHaveCount(1)
        ->and($activeHashes->first()->hashable_id)->toBe(1);
});
