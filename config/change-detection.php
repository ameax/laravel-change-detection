<?php

declare(strict_types=1);

// config for Ameax/LaravelChangeDetection
return [
    /*
    |--------------------------------------------------------------------------
    | Database Connection
    |--------------------------------------------------------------------------
    |
    | Specify the database connection to use for package tables. Leave null
    | to use the default connection. This allows storing hash data in a
    | different database than your models.
    |
    */
    'database_connection' => null,

    /*
    |--------------------------------------------------------------------------
    | Table Names
    |--------------------------------------------------------------------------
    |
    | Here you can configure the table names used by the package. This allows
    | you to avoid naming conflicts with existing tables in your application.
    |
    */
    'tables' => [
        'hashes' => 'hashes',
        'publishers' => 'publishers',
        'publishes' => 'publishes',
        'hash_dependents' => 'hash_dependents',
    ],

    /*
    |--------------------------------------------------------------------------
    | Retry Configuration
    |--------------------------------------------------------------------------
    |
    | Configure the retry intervals for failed publish attempts.
    | Times are in seconds.
    |
    */
    'retry_intervals' => [
        1 => 30,        // First retry after 30 seconds
        2 => 300,       // Second retry after 5 minutes (300 seconds)
        3 => 21600,     // Third retry after 6 hours (21600 seconds)
    ],

    /*
    |--------------------------------------------------------------------------
    | Queue Configuration
    |--------------------------------------------------------------------------
    |
    | Configure which queues should be used for processing.
    |
    */
    'queues' => [
        'publish' => 'default',
        'detect_changes' => 'default',
    ],

    /*
    |--------------------------------------------------------------------------
    | Hash Algorithm
    |--------------------------------------------------------------------------
    |
    | The hash algorithm to use for generating hashes.
    | Supported: "md5", "sha256"
    |
    */
    'hash_algorithm' => 'md5',

    /*
    |--------------------------------------------------------------------------
    | Job Unique Lock Duration
    |--------------------------------------------------------------------------
    |
    | How long (in seconds) should a job remain unique after being dispatched.
    | This prevents duplicate jobs from being added to the queue.
    | Should be longer than the dispatch delay but shorter than job duration.
    | Default: 20 seconds
    |
    */
    'job_unique_for' => env('CHANGE_DETECTION_JOB_UNIQUE_FOR', 20),

    /*
    |--------------------------------------------------------------------------
    | Job Timeout
    |--------------------------------------------------------------------------
    |
    | Maximum execution time (in seconds) for the BulkPublishJob.
    | Should be set high enough to process a full batch including delays.
    | Default: 1800 seconds (30 minutes)
    |
    */
    'job_timeout' => env('CHANGE_DETECTION_JOB_TIMEOUT', 1800),

    /*
    |--------------------------------------------------------------------------
    | Job Dispatch Delay
    |--------------------------------------------------------------------------
    |
    | Delay (in seconds) before dispatching the next batch job.
    | This prevents overwhelming the server with continuous job execution.
    | Default: 10 seconds
    |
    */
    'job_dispatch_delay' => env('CHANGE_DETECTION_JOB_DISPATCH_DELAY', 10),

    /*
    |--------------------------------------------------------------------------
    | Sync Job Configuration
    |--------------------------------------------------------------------------
    |
    | Configure the SyncHashesJob behavior.
    |
    */
    'sync_job_unique_for' => env('CHANGE_DETECTION_SYNC_JOB_UNIQUE_FOR', 120),
    'sync_job_timeout' => env('CHANGE_DETECTION_SYNC_JOB_TIMEOUT', 3600),

    /*
    |--------------------------------------------------------------------------
    | Log Channels
    |--------------------------------------------------------------------------
    |
    | Configure which log channels to use for different operations.
    | Set to null to use the default log channel.
    |
    */
    'log_channels' => [
        'change_detection' => env('CHANGE_DETECTION_LOG_CHANNEL', null),
        'publishing' => env('CHANGE_DETECTION_PUBLISHING_LOG_CHANNEL', null),
    ],
];
