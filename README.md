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

### Detect Changes Command

Comprehensive command for monitoring and managing hash changes:

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

Publishers can define their own rate limiting and batch processing settings:

```php
use Ameax\LaravelChangeDetection\Contracts\Publisher;

class CustomPublisher implements Publisher
{
    public function getBatchSize(): int
    {
        return 1000; // Process 1000 records per batch
    }

    public function getDelayMs(): int
    {
        return 0; // No delay between records (e.g., for SFTP exports)
    }

    public function getMaxAttempts(): int
    {
        return 3; // Retry failed publishes 3 times
    }

    // ... other required methods
}
```

### Processing Publishes

The publishing system uses publisher-specific settings for optimal performance:

```bash
# Process all pending publishes using each publisher's configuration
php artisan change-detection:process-publishes

# Process synchronously with progress feedback
php artisan change-detection:process-publishes --sync

# Force processing even if another job is running
php artisan change-detection:process-publishes --force
```

### Publisher Examples

**API Publisher (with rate limiting)**:
```php
public function getBatchSize(): int { return 50; }   // Small batches for API
public function getDelayMs(): int { return 200; }    // 200ms delay for rate limits
```

**SFTP Export Publisher (no limits)**:
```php
public function getBatchSize(): int { return 0; }    // Unlimited batch size
public function getDelayMs(): int { return 0; }      // No delay between records
```

**Log Publisher (moderate batching)**:
```php
public function getBatchSize(): int { return 10; }   // Small batches to avoid log spam
public function getDelayMs(): int { return 100; }    // 100ms delay between logs
```

### Immediate Publishing

For urgent records that need immediate publishing:

```php
// Publish a single record immediately (synchronously)
$publishRecord->publishNow();

// Smart publishing - immediate if no bulk job is running
$publishRecord->publishImmediatelyOrQueue();
```

### Bulk Processing

The `BulkPublishJob` automatically:
- Groups records by publisher type
- Uses each publisher's `getBatchSize()` and `getDelayMs()` settings
- Processes only one instance at a time (unique job)
- Chains to next batch if more records exist
- Logs detailed progress per publisher

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
