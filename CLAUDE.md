# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Package Overview
This is a Laravel package called `laravel-change-detection` by Ameax. It's built using the Spatie Laravel Package Tools framework and follows standard Laravel package conventions.

## Development Commands

### Testing
- `composer test` - Run PHPUnit/Pest tests
- `vendor/bin/pest` - Run tests directly
- `vendor/bin/pest --coverage` - Run tests with coverage

### Code Quality
- `composer analyse` - Run PHPStan static analysis (level 5)
- `vendor/bin/phpstan analyse` - Run PHPStan directly
- `composer format` - Format code using Laravel Pint
- `vendor/bin/pint` - Format code directly

### Package Setup
- `composer run prepare` - Discover package (runs automatically after autoload-dump)

## Architecture

### Package Structure
- **Namespace**: `Ameax\LaravelChangeDetection`
- **Service Provider**: `LaravelChangeDetectionServiceProvider` extends Spatie's `PackageServiceProvider`
- **Main Class**: `LaravelChangeDetection` (currently empty)
- **Facade**: `LaravelChangeDetection` facade available
- **Commands**: `LaravelChangeDetectionCommand` artisan command
- **Config**: `config/change-detection.php` (currently empty)
- **Migration**: Creates `laravel_change_detection_table`

### Key Dependencies
- PHP 8.3+
- Laravel 12.0+ (illuminate/contracts)
- Spatie Laravel Package Tools for package structure
- Pest for testing
- PHPStan for static analysis
- Laravel Pint for code formatting

### Testing Framework
Uses Pest with Orchestra Testbench for Laravel package testing. Tests are located in `tests/` directory with `TestCase.php` base class.

### Code Standards
- PHPStan level 7 analysis
- Laravel Pint for code formatting
- Octane compatibility checking enabled
- Model property checking enabled

### Interface Requirements
The `Hashable` interface requires:
- `getHashableAttributes(): array` - Attributes to include in hash
- `getHashCompositeDependencies(): array` - Child relations that affect this model's hash
- `getHashParentRelations(): array` - Parent relations to notify when this model changes
- `getHashableScope(): ?\Closure` - Optional scope to filter which records get hashed

## Implementation Status

All core functionality is fully implemented and tested:

✅ **Core Features**
- Hash calculation and storage
- Change detection with MySQL optimization
- Composite dependencies (parent-child relationships)
- Scope filtering for selective hashing
- Soft delete support
- Cross-database support

✅ **Services**
- BulkHashProcessor for high-performance batch operations
- ChangeDetector for efficient change detection
- MySQLHashCalculator for optimized hash calculations
- DependencyHashCalculator for composite hashes
- CompositeHashCalculator for combined hash logic

✅ **Commands**
- `change-detection:sync` - Main synchronization command
- `change-detection:detect` - Fine-grained change detection
- `change-detection:truncate` - Reset system tables
- `change-detection:purge` - Clean up deleted hashes

✅ **Publishing System**
- Publisher interface and models
- LogPublisher for development
- Retry logic with configurable intervals
- Error categorization and tracking

### Key Architectural Decisions

- **MySQL-First Development**: All tests run against MySQL
- **Morph Names**: Use short names ('user') instead of full class names
- **Max 200 Lines**: Strict limit per class for maintainability
- **Cross-Database Support**: Hash tables can be in different DB than models
- **Performance Critical**: MySQL-based hash calculations for large datasets
- **Explicit Parent Relations**: Child models explicitly define parent relations via `getHashParentRelations()`
- **Scope Enforcement**: Only models within their defined scope get hash records

## Important Behavioral Notes

### Scope Filtering
- **In-Scope Only**: Hashes are only created for models that match their `getHashableScope()` criteria
- **Dependency Filtering**: Dependencies are only created to/from models that are in scope
- **Soft Deletion**: When a model goes out of scope, its hash is soft-deleted (marked with `deleted_at`)
- **Parent Scope Check**: Parent dependencies are only created if the parent model is also in scope

### Publish Records
- **ONE publish record per hash**: The system maintains exactly ONE publish record per main hash record
- **Not per change**: Publish records are NOT created for every change event
- **Initial creation only**: A publish record is created when a hash is first created, not on subsequent updates
- **Purpose**: Tracks that a hash needs to be published to external systems, not individual changes

### Dependency Direction
- **Child Dependencies** (`getHashCompositeDependencies`): Parent model's hash depends on these children
- **Parent Relations** (`getHashParentRelations`): Child notifies these parents when it changes
- **Relation Names**: Stored in `hash_dependents.relation_name` for parent dependencies