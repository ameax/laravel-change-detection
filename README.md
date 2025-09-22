# Laravel Change Detection

[![Latest Version on Packagist](https://img.shields.io/packagist/v/ameax/laravel-change-detection.svg?style=flat-square)](https://packagist.org/packages/ameax/laravel-change-detection)
[![GitHub Tests Action Status](https://img.shields.io/github/actions/workflow/status/ameax/laravel-change-detection/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/ameax/laravel-change-detection/actions?query=workflow%3Arun-tests+branch%3Amain)
[![GitHub Code Style Action Status](https://img.shields.io/github/actions/workflow/status/ameax/laravel-change-detection/fix-php-code-style-issues.yml?branch=main&label=code%20style&style=flat-square)](https://github.com/ameax/laravel-change-detection/actions?query=workflow%3A"Fix+PHP+code+style+issues"+branch%3Amain)
[![Total Downloads](https://img.shields.io/packagist/dt/ameax/laravel-change-detection.svg?style=flat-square)](https://packagist.org/packages/ameax/laravel-change-detection)

A high-performance Laravel package for tracking and detecting changes in Eloquent models using cryptographic hashes. Perfect for audit trails, cache invalidation, external system synchronization, and data integrity monitoring in large-scale applications.

Laravel Change Detection automatically calculates and stores MD5/SHA256 hashes of your model attributes, supports composite dependency tracking, provides MySQL-optimized bulk operations for 100k+ records, and includes comprehensive CLI tools for monitoring and maintenance.

## Features

- **🚀 High Performance**: MySQL-optimized hash calculations for 100k+ records
- **🔄 Composite Dependencies**: Track changes across related models automatically
- **⚡ Bulk Operations**: Efficient batch processing with configurable limits
- **🗃️ Cross-Database Support**: Hash tables can be in different databases than models
- **🛠️ CLI Tools**: Comprehensive commands for monitoring and maintenance
- **📊 Queue Integration**: Background processing with retry logic
- **🔍 Change Detection**: Instant detection of model modifications
- **💾 Soft Delete Support**: Proper handling of deleted records
- **🧹 Cleanup Tools**: Automatic orphaned hash detection and removal
- **📝 Debug Logging**: Built-in LogPublisher for development

## Installation

You can install the package via composer:

```bash
composer require ameax/laravel-change-detection
```

Publish and run the migrations:

```bash
php artisan vendor:publish --tag="laravel-change-detection-migrations"
php artisan migrate
```

Optionally, publish the config file:

```bash
php artisan vendor:publish --tag="laravel-change-detection-config"
```

## Configuration

The published config file (`config/change-detection.php`) includes:

```php
return [
    // Database connection for hash tables (null = default)
    'database_connection' => null,

    // Custom table names
    'tables' => [
        'hashes' => 'hashes',
        'hash_dependents' => 'hash_dependents',
        'publishers' => 'publishers',
        'publishes' => 'publishes',
    ],

    // Queue configuration
    'queues' => [
        'publish' => 'default',
        'detect_changes' => 'default',
    ],

    // Hash algorithm: 'md5' or 'sha256'
    'hash_algorithm' => 'md5',

    // Retry intervals for failed publishes (in seconds)
    'retry_intervals' => [
        1 => 30,    // First retry after 30 seconds
        2 => 300,   // Second retry after 5 minutes
        3 => 21600, // Third retry after 6 hours
    ],
];
```

## Quick Start

### 1. Make Your Model Hashable

```php
use Ameax\LaravelChangeDetection\Contracts\Hashable;
use Ameax\LaravelChangeDetection\Traits\InteractsWithHashes;
use Illuminate\Database\Eloquent\Model;

class User extends Model implements Hashable
{
    use InteractsWithHashes;

    protected $fillable = ['name', 'email', 'status'];

    // Define which attributes to include in hash calculation
    public function getHashableAttributes(): array
    {
        return ['name', 'email', 'status'];
    }

    // Define dependencies (optional - for composite hashing)
    public function getHashCompositeDependencies(): array
    {
        return ['posts']; // Hash changes when user's posts change
    }

    public function posts()
    {
        return $this->hasMany(Post::class);
    }
}
```

### 2. Automatic Hash Generation

Hashes are automatically calculated and stored when models are saved:

```php
$user = User::create([
    'name' => 'John Doe',
    'email' => 'john@example.com',
    'status' => 'active'
]);

// Hash is automatically generated and stored
$currentHash = $user->getCurrentHash();
echo $currentHash->composite_hash; // e.g., "a1b2c3d4e5f6..."
```

### 3. Change Detection

```php
// Check if model has changed since last hash calculation
if ($user->hasHashChanged()) {
    echo "User has been modified!";
}

// Manually recalculate hash
$user->updateHash();

// Force hash update regardless of changes
$newHash = $user->forceHashUpdate();
```

## Advanced Usage

### Composite Dependencies

Track changes across related models:

```php
class Article extends Model implements Hashable
{
    use InteractsWithHashes;

    public function getHashableAttributes(): array
    {
        return ['title', 'content', 'author'];
    }

    // Article hash changes when replies change
    public function getHashCompositeDependencies(): array
    {
        return ['replies'];
    }

    public function replies()
    {
        return $this->hasMany(Reply::class);
    }
}

class Reply extends Model implements Hashable
{
    use InteractsWithHashes;

    public function getHashableAttributes(): array
    {
        return ['content', 'author'];
    }

    // No dependencies
    public function getHashCompositeDependencies(): array
    {
        return [];
    }
}
```

When a reply is created/updated/deleted, the parent article's hash automatically updates.

## CLI Commands

### Sync Command (Recommended)

The simplest way to synchronize all hash records with a single command:

```bash
# Synchronize all hashes (auto-discover, detect, cleanup, and update)
php artisan change-detection:sync

# Preview changes without making modifications
php artisan change-detection:sync --dry-run

# Show detailed report of operations
php artisan change-detection:sync --report

# Limit processing per model (useful for large datasets)
php artisan change-detection:sync --limit=1000

# Immediately purge orphaned hashes instead of soft-deleting
php artisan change-detection:sync --purge

# Sync specific models only
php artisan change-detection:sync --models="App\Models\User,App\Models\Post"
```

This command combines auto-discovery, change detection, orphan cleanup, and hash updates in one operation.

### Detect Changes Command

For fine-grained control over change detection:

```bash
# Auto-discover and check all hashable models
php artisan change-detection:detect --auto-discover

# Check specific models
php artisan change-detection:detect --models="App\Models\User,App\Models\Post"

# Update detected changes
php artisan change-detection:detect --auto-discover --update

# Clean up orphaned hashes
php artisan change-detection:detect --auto-discover --cleanup

# Show detailed report
php artisan change-detection:detect --auto-discover --report

# Limit processing (useful for large datasets)
php artisan change-detection:detect --auto-discover --limit=1000
```

### Truncate Command

Reset the change detection system by clearing all tables:

```bash
# Truncate all change detection tables
php artisan change-detection:truncate

# Skip confirmation prompt
php artisan change-detection:truncate --force

# Only truncate specific tables
php artisan change-detection:truncate --only=hashes,publishes
```

### Purge Deleted Hashes

Remove soft-deleted hash records:

```bash
# Purge all deleted hashes
php artisan change-detection:purge

# Purge hashes deleted more than 7 days ago
php artisan change-detection:purge --older-than=7

# Preview what would be purged
php artisan change-detection:purge --dry-run

# Skip confirmation
php artisan change-detection:purge --force
```

### Background Processing

Queue change detection for large datasets:

```php
use Ameax\LaravelChangeDetection\Jobs\DetectChangesJob;

// Queue change detection for a specific model
DetectChangesJob::dispatch(
    modelClass: User::class,
    updateHashes: true,
    cleanupOrphaned: true,
    limit: 5000
);
```

## Bulk Operations

For high-performance scenarios with 100k+ records:

```php
use Ameax\LaravelChangeDetection\Services\BulkHashProcessor;
use Ameax\LaravelChangeDetection\Services\ChangeDetector;

$processor = app(BulkHashProcessor::class);
$detector = app(ChangeDetector::class);

// Count changed models efficiently
$changedCount = $detector->countChangedModels(User::class);

// Process changes in batches
$updatedCount = $processor->processChangedModels(User::class, limit: 1000);

// Process specific model IDs
$modelIds = [1, 2, 3, 4, 5];
$updatedCount = $processor->updateHashesForIds(User::class, $modelIds);
```

## Orphaned Hash Cleanup

Automatically detect and clean up hashes for deleted models:

```php
use Ameax\LaravelChangeDetection\Services\OrphanedHashDetector;

$detector = app(OrphanedHashDetector::class);

// Count orphaned hashes
$orphanedCount = $detector->countOrphanedHashes(User::class);

// Get orphaned hash details
$orphanedHashes = $detector->detectOrphanedHashes(User::class);

// Clean up orphaned hashes
$cleanedCount = $detector->cleanupOrphanedHashes(User::class);

// Handle soft-deleted models
$softDeletedCount = $detector->cleanupSoftDeletedModelHashes(User::class);
```

## Cross-Database Support

Store hash tables in a different database than your models:

```php
// config/change-detection.php
return [
    'database_connection' => 'analytics', // Use different connection
    // ... other config
];

// database/config.php
'connections' => [
    'mysql' => [
        // Your main database
        'database' => 'main_app',
    ],
    'analytics' => [
        // Separate database for hash storage
        'driver' => 'mysql',
        'database' => 'analytics_db',
        'host' => 'analytics-server',
        // ... connection details
    ],
],
```

## Publishing System

### Publisher Configuration

Publishers implement the `Publisher` contract with comprehensive rate limiting and error handling configuration:

```php
use Ameax\LaravelChangeDetection\Contracts\Publisher;

class CustomPublisher implements Publisher
{
    // Rate limiting configuration
    public function getBatchSize(): int
    {
        return 1000; // Process 1000 records per batch
    }

    public function getDelayMs(): int
    {
        return 100; // 100ms delay between each record
    }

    public function getRetryIntervals(): array
    {
        return [30, 300, 3600]; // Retry after 30s, 5m, 1h
    }

    // Error handling configuration
    public function getMaxValidationErrors(): int
    {
        return 50; // Stop job after 50 validation errors
    }

    public function getMaxInfrastructureErrors(): int
    {
        return 1; // Stop job after 1 infrastructure error
    }

    public function handlePublishException(\Throwable $exception): string
    {
        // Return 'stop_job', 'fail_record', or 'defer_record'
        if (str_contains($exception->getMessage(), 'Connection refused')) {
            return 'stop_job'; // Critical infrastructure issue
        }

        return 'defer_record'; // Retry later
    }

    // Publishing logic
    public function shouldPublish($model): bool
    {
        return $model->status === 'active';
    }

    public function getData($model): array
    {
        return $model->toArray();
    }

    public function publish($model, array $data): bool
    {
        // Your publishing logic here
        return $this->sendToExternalAPI($data);
    }
}
```

### Error Handling & Recovery

The publishing system tracks errors with detailed categorization:

```php
// Check publish status for a model
$user = User::find(1);
$status = $user->getPublishStatus();

// Returns:
// [
//     'publisher_name' => [
//         'status' => 'failed|deferred|completed',
//         'last_error' => 'Error message',
//         'error_type' => 'validation|infrastructure|data',
//         'response_code' => 404,
//         'attempt_count' => 3,
//         'last_attempted_at' => '2024-01-15 10:30:00'
//     ]
// ]

// Reset errors for debugging
$user->resetPublishErrors(); // Reset all publishers
$user->resetPublishErrorsForPublisher($publisherId); // Reset specific publisher

// Get error count
$errorCount = $user->getPublishErrorCount();
```

### Processing Publishes

The `BulkPublishJob` processes publishes with intelligent error handling:

```bash
# Queue bulk publish job (recommended)
php artisan change-detection:process-publishes

# Process synchronously with real-time feedback
php artisan change-detection:process-publishes --sync

# Force processing even if another job is running
php artisan change-detection:process-publishes --force

# Process specific number of records
php artisan change-detection:process-publishes --limit=500
```

The job automatically:
- **Single Instance**: Only one bulk job runs at a time using Laravel's `ShouldBeUnique`
- **Publisher Grouping**: Groups records by publisher for optimal batch processing
- **Rate Limiting**: Uses each publisher's `getBatchSize()` and `getDelayMs()` settings
- **Error Differentiation**: Tracks validation vs infrastructure errors separately
- **Smart Stopping**: Stops processing when error thresholds are reached
- **Response Tracking**: Captures HTTP response codes for debugging
- **Automatic Chaining**: Dispatches next batch if more records exist

### Publisher Examples

**API Publisher (with strict rate limiting)**:
```php
class ApiPublisher implements Publisher
{
    public function getBatchSize(): int { return 50; }
    public function getDelayMs(): int { return 200; }
    public function getMaxValidationErrors(): int { return 10; }
    public function getMaxInfrastructureErrors(): int { return 1; }

    public function handlePublishException(\Throwable $exception): string
    {
        if (str_contains($exception->getMessage(), '429')) {
            return 'stop_job'; // Rate limit hit, stop job
        }
        return 'defer_record';
    }
}
```

**SFTP Export Publisher (no limits)**:
```php
class SftpExportPublisher implements Publisher
{
    public function getBatchSize(): int { return 0; }      // Unlimited
    public function getDelayMs(): int { return 0; }        // No delay
    public function getMaxValidationErrors(): int { return 0; } // No limit
    public function getMaxInfrastructureErrors(): int { return 1; } // Stop on connection issues
}
```

**Email Publisher (moderate batching)**:
```php
class EmailPublisher implements Publisher
{
    public function getBatchSize(): int { return 100; }
    public function getDelayMs(): int { return 50; }
    public function getMaxValidationErrors(): int { return 20; } // Invalid emails
    public function getMaxInfrastructureErrors(): int { return 3; } // SMTP issues
}
```

### Immediate Publishing

For urgent records that bypass the queue system:

```php
// Publish immediately (synchronous)
$success = $publishRecord->publishNow();

// Smart publishing - immediate if no bulk job running, else queue
$publishRecord->publishImmediatelyOrQueue();

// Model-level immediate publishing
$user = User::find(1);
$user->publishImmediately(); // Publishes to all configured publishers
```

### Error Types & Response Codes

The system categorizes errors for targeted handling:

- **Validation Errors**: Invalid data format, missing required fields
- **Infrastructure Errors**: Network timeouts, connection failures, authentication
- **Data Errors**: Missing models, empty data sets

Response codes are captured for HTTP-based publishers:
```php
// Publish records store detailed error information
$publishRecord = Publish::find(1);
echo $publishRecord->last_error_message;     // "HTTP 404: Resource not found"
echo $publishRecord->last_response_code;     // 404
echo $publishRecord->error_type;             // "validation"
echo $publishRecord->attempt_count;          // 3
```

### Bulk Processing Details

The `BulkPublishJob` processing flow:

1. **Acquire Lock**: Prevents multiple instances using cache locks
2. **Group by Publisher**: Processes each publisher type separately
3. **Load Settings**: Uses publisher-specific batch size and delays
4. **Process Batch**: Handles records with comprehensive error tracking
5. **Error Monitoring**: Stops when error thresholds are exceeded
6. **Chain Next Batch**: Automatically dispatches follow-up jobs
7. **Release Lock**: Ensures clean shutdown

## Development & Debugging

### LogPublisher for Development

```php
use Ameax\LaravelChangeDetection\Publishers\LogPublisher;

// Configure log publisher
$publisher = new LogPublisher(
    logChannel: 'change-detection',
    logLevel: 'debug',
    includeHashData: true
);

// Get model data for debugging
$data = $publisher->getData($user);

// Publish to logs
$publisher->publish($user, $data);
```

### Hash Information Methods

```php
// Get current hash record
$hashRecord = $user->getCurrentHash();

// Get hash dependents (models that depend on this one)
$dependents = $user->getHashDependents();

// Get publish records for this hash
$publishes = $user->getHashPublishes();

// Check if hash is deleted
$isDeleted = $user->isHashDeleted();

// Get last hash update time
$lastUpdated = $user->getHashLastUpdated();

// Calculate hashes without storing
$attributeHash = $user->calculateAttributeHash();
$compositeHash = $user->calculateCompositeHash();
```

### Error Management Methods

```php
// Reset all publish errors for this model
$resetCount = $user->resetPublishErrors();

// Reset errors for specific publisher
$resetCount = $user->resetPublishErrorsForPublisher($publisherId);

// Get total error count across all publishers
$errorCount = $user->getPublishErrorCount();

// Get detailed publish status for all publishers
$status = $user->getPublishStatus();
// Returns array with publisher status details

// Model can be published immediately (bypassing queue)
$user->publishImmediately();
```

## Performance Considerations

### MySQL Optimization

This package is optimized for MySQL with direct SQL queries:

- Hash calculations use MySQL's native `MD5()` and `SHA2()` functions
- Bulk operations use `INSERT...ON DUPLICATE KEY UPDATE` for efficiency
- Cross-database JOINs are properly qualified
- Configurable batch sizes for memory management

### Large Dataset Recommendations

For applications with 100k+ records:

```php
// Use bulk operations instead of individual model methods
$processor = app(BulkHashProcessor::class);
$processor->setBatchSize(5000); // Adjust based on memory

// Use background jobs for large operations
DetectChangesJob::dispatch(User::class, limit: 10000);

// Use limits when detecting changes
php artisan change-detection:detect --auto-discover --limit=5000
```

## Common Use Cases

### 1. Cache Invalidation

```php
class ProductController extends Controller
{
    public function show(Product $product)
    {
        $cacheKey = "product.{$product->id}.{$product->getCurrentHash()->composite_hash}";

        return Cache::remember($cacheKey, 3600, function () use ($product) {
            return $this->generateProductView($product);
        });
    }
}
```

### 2. External System Synchronization

```php
// Detect changed products for API sync
$changedProducts = app(ChangeDetector::class)
    ->detectChangedModels(Product::class, limit: 100);

foreach ($changedProducts as $product) {
    // Sync to external system
    $this->syncToExternalAPI($product);

    // Update hash after successful sync
    $product->updateHash();
}
```

### 3. Audit Trail Integration

```php
class User extends Model implements Hashable
{
    use InteractsWithHashes;

    protected static function booted()
    {
        static::updated(function ($user) {
            if ($user->hasHashChanged()) {
                AuditLog::create([
                    'model_type' => 'user',
                    'model_id' => $user->id,
                    'old_hash' => $user->getOriginal('hash'),
                    'new_hash' => $user->getCurrentHash()->composite_hash,
                    'changed_at' => now(),
                ]);
            }
        });
    }
}
```

### 4. Data Integrity Monitoring

```php
// Daily integrity check
$command = Artisan::call('change-detection:detect', [
    '--auto-discover' => true,
    '--report' => true,
]);

// Queue periodic cleanup
Schedule::job(new DetectChangesJob(User::class, false, true))
    ->daily()
    ->description('Clean up orphaned user hashes');
```

## Testing

Run the test suite:

```bash
composer test
```

Run static analysis:

```bash
composer analyse
```

Format code:

```bash
composer format
```

The package includes comprehensive tests for:
- Hash calculation and storage
- Composite dependency tracking
- Bulk operations and performance
- Cross-database functionality
- CLI commands and background jobs
- Change detection algorithms

## Requirements

- **PHP**: 8.3+
- **Laravel**: 12.0+
- **Database**: MySQL 8.0+ (optimized for MySQL)
- **Extensions**: PDO MySQL

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## Contributing

Please see [CONTRIBUTING](CONTRIBUTING.md) for details.

## Security Vulnerabilities

Please review [our security policy](../../security/policy) on how to report security vulnerabilities.

## Architecture

The package follows clean architecture principles:

- **Services**: Core business logic (ChangeDetector, BulkHashProcessor, etc.)
- **Models**: Database entities with proper relationships
- **Contracts**: Interfaces for extensibility
- **Traits**: Reusable functionality for models
- **Commands**: CLI tools for management
- **Jobs**: Background processing capabilities

All classes are kept under 200 lines for maintainability, and the codebase follows PSR-12 coding standards with comprehensive type safety.

## Credits

- [Michael Schmidt](https://github.com/69188126+ms-aranes)
- Built with [Spatie Laravel Package Tools](https://github.com/spatie/laravel-package-tools)
- [All Contributors](../../contributors)

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
