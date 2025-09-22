<?php

use Ameax\LaravelChangeDetection\Models\Hash;
use Illuminate\Support\Facades\DB;

it('keeps database tables after tests', function () {
    $tables = DB::connection('testing')->select('SHOW TABLES');
    $tableNames = array_map(fn ($table) => array_values((array) $table)[0], $tables);

    expect($tableNames)->toContain('hashes');
    expect($tableNames)->toContain('hash_dependents');
    expect($tableNames)->toContain('publishes');
    expect($tableNames)->toContain('publishers');
});

it('can query data from previous test runs when MIGRATED=1', function () {
    if (env('MIGRATED') == 1) {
        $count = Hash::count();
        expect($count)->toBeGreaterThanOrEqual(0);
    } else {
        expect(true)->toBeTrue();
    }
});

it('database connection is configured correctly', function () {
    $connection = config('database.default');
    expect($connection)->toBe('testing');

    $config = config('database.connections.testing');
    expect($config['driver'])->toBe('mysql');
    expect($config['database'])->toBe('laravel_change_detection_test');
});