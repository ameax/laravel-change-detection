<?php

use Ameax\LaravelChangeDetection\Tests\TestCase;

uses(TestCase::class)->in(__DIR__);

// Load helper functions
require_once __DIR__.'/Feature/Helpers/HashSyncHelpers.php';
require_once __DIR__.'/Feature/Helpers/AnimalHelpers.php';
require_once __DIR__.'/Feature/Helpers/WeatherStationHelpers.php';
require_once __DIR__.'/Feature/Helpers/PerformanceHelpers.php';
require_once __DIR__.'/Feature/Helpers/PublisherHelpers.php';

// Load datasets
require_once __DIR__.'/Datasets/AnimalSyncDatasets.php';
