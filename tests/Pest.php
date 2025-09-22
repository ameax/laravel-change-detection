<?php

use Ameax\LaravelChangeDetection\Tests\TestCase;

uses(TestCase::class)->in(__DIR__);

// Load helper functions
require_once __DIR__.'/Feature/Helpers/SyncCommandHelpers.php';

// Load datasets
require_once __DIR__.'/Datasets/AnimalSyncDatasets.php';
