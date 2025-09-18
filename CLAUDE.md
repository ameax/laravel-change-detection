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

## Implementation Approach

This package is being rebuilt from `laravel-hash-change-detector` following a phase-by-phase approach detailed in `import/laravel-hash-change-detector/docs/PACKAGE_REBUILD_PLAN.md`. Each phase must be:

1. **Discussed first** - Review requirements and approach
2. **Implemented** - Complete the phase functionality
3. **Tested** - Ensure all tests pass
4. **Committed** - Create atomic commits per phase

### Implementation Phases

**Phase 0: Repository Setup** ✅ COMPLETED
- Config file migration (1:1 from old package, no API section)
- Test environment with MySQL
- Basic package structure

**Phase 1: Migrations & Models** ✅ COMPLETED
- Copy migrations with `deleted_at` extension
- Copy Models: Hash, HashDependent, Publish, Publisher
- Copy Interfaces: Hashable, Publisher
- Rewrite InteractsWithHashes trait (simplified)

**Phase 2: Hash Calculation** ✅ COMPLETED
- MySQLHashCalculator for performance
- DependencyHashCalculator for composite hashes
- CompositeHashCalculator integration

**Phase 3: Change Detection**
- ChangeDetector service
- HashUpdater service
- Integration with model events

**Phase 4: Model Integration**
- InteractsWithHashes trait implementation
- Model observers for automatic updates
- Hash relationship methods

**Phase 5: Composite Dependencies Testing**
- Complex test models (Article -> Comments -> Replies)
- Integration tests for nested dependencies
- Circular dependency prevention

**Phase 6: MySQL Optimization**
- BulkHashProcessor for performance
- Cross-database support
- Missing record detection for deletions

**Phase 7: Commands & Jobs**
- DetectChangesCommand (intelligent)
- DetectChangesJob for queuing
- LogPublisher for development

### Key Architectural Decisions

- **MySQL-First Development**: All tests run against MySQL
- **Morph Names**: Use short names ('user') instead of full class names
- **Max 200 Lines**: Strict limit per class for maintainability
- **Cross-Database Support**: Hash tables can be in different DB than models
- **Performance Critical**: MySQL-based hash calculations for large datasets