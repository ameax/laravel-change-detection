<?php

use Ameax\LaravelChangeDetection\Models\Hash;

it('can create a hash record', function () {
    $hash = Hash::create([
        'hashable_type' => 'test_model',
        'hashable_id' => 1,
        'attribute_hash' => 'test_hash_value',
    ]);

    expect($hash)->toBeInstanceOf(Hash::class);
    expect($hash->hashable_type)->toBe('test_model');
    expect($hash->hashable_id)->toBe(1);
    expect($hash->attribute_hash)->toBe('test_hash_value');
});

it('can find existing hash records', function () {
    Hash::create([
        'hashable_type' => 'test_model',
        'hashable_id' => 2,
        'attribute_hash' => 'another_hash',
    ]);

    $found = Hash::where('hashable_type', 'test_model')
        ->where('hashable_id', 2)
        ->first();

    expect($found)->not->toBeNull();
    expect($found->attribute_hash)->toBe('another_hash');
});