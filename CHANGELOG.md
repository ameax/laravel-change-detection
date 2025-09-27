# Changelog

All notable changes to `laravel-change-detection` will be documented in this file.

## [Unreleased]

### Added
- Added `has_dependencies_built` flag to hashes table for efficient dependency tracking
- Added `ModelDiscoveryHelper` class for dynamic model resolution via Morph Map and Publishers
- Added `BulkHashProcessor::buildPendingDependencies()` method for processing unbuild dependencies
- Added batch update optimization for dependency flags to prevent N+1 queries
- Added support for model discovery from multiple sources (Publishers, Morph Map, App\Models)

### Changed
- Increased default batch processing chunk size from 100 to 1000 records for better performance
- Modified sync command to process dependency models before main models
- Improved bulk operations to handle 100k+ records efficiently
- Updated model discovery to support models outside of App\Models namespace

### Fixed
- Fixed hash_dependent records not being created during initial sync
- Fixed N+1 query issue when marking dependencies as built
- Fixed dependency building for models with composite dependencies
