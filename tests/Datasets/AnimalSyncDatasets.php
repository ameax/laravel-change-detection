<?php

dataset('weight_changes', [
    'stays in scope (heavy to heavier)' => [
        'initial_weight' => 4.2,
        'new_weight' => 5.5,
        'leaves_scope' => false,
        'enters_scope' => false,
        'expected_active_hashes' => 3, // Cat becomes heavy (4.2), then heavier (5.5), Dog & Horse stay heavy
    ],
    'leaves scope (heavy to light)' => [
        'initial_weight' => 4.2,
        'new_weight' => 1.9,
        'leaves_scope' => true,
        'enters_scope' => false,
        // Cat starts light, becomes heavy (4.2), then light (1.9) - gets soft deleted
        // Dog stays heavy, Horse stays heavy = 2 active
        'expected_active_hashes' => 2,
    ],
    'enters scope (light to heavy)' => [
        'initial_weight' => 2.5,
        'new_weight' => 3.5,
        'leaves_scope' => false,
        'enters_scope' => true,
        // Cat enters scope, Dog & Horse already in scope = 3 active
        'expected_active_hashes' => 3,
    ],
]);

dataset('sync_options', [
    'soft delete' => [
        'options' => [],
        'hard_delete' => false,
    ],
    'hard delete with purge' => [
        'options' => ['--purge' => true],
        'hard_delete' => true,
    ],
]);

dataset('publisher_statuses', [
    'active publisher' => [
        'status' => 'active',
        'should_publish' => true,
        'expected_publishes' => 2,
    ],
    'inactive publisher' => [
        'status' => 'inactive',
        'should_publish' => false,
        'expected_publishes' => 0,
    ],
]);
