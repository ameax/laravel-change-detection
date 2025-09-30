<?php

use Ameax\LaravelChangeDetection\Models\Hash;
use Ameax\LaravelChangeDetection\Models\HashDependent;
use Ameax\LaravelChangeDetection\Services\BulkHashProcessor;
use Ameax\LaravelChangeDetection\Tests\Models\TestWeatherStation;
use Ameax\LaravelChangeDetection\Tests\Models\TestWindvane;

/**
 * Query Performance Analysis Results
 * ==================================
 *
 * Date: 2025-09-30
 * Dataset: 4000 hash records, 3000 dependencies
 *
 * PERFORMANCE RESULTS:
 * - Updated 1000 parent composite hashes in 17.95 ms
 * - Production test (74k records): 3 seconds
 *
 * EXISTING INDEXES:
 *
 * hashes table:
 * - PRIMARY (id) - Used for joins
 * - hashes_hashable_type_hashable_id_unique (hashable_type, hashable_id) - UNIQUE constraint
 * - hashes_hashable_type_hashable_id_index (hashable_type, hashable_id) - Duplicate, but kept for consistency
 * - hashes_deleted_at_index (deleted_at) - For filtering soft-deleted records
 *
 * hash_dependents table:
 * - PRIMARY (id)
 * - unique_hash_dependent (hash_id, dependent_model_type, dependent_model_id) - UNIQUE constraint
 * - dependent_model_index (dependent_model_type, dependent_model_id) - ✅ USED by correlated subquery
 *
 * EXPLAIN ANALYSIS:
 *
 * Main UPDATE Query:
 * - Table: h, Type: ALL (full table scan), Rows: 3980
 * - This is OPTIMAL - MySQL chooses full scan because:
 *   * Nearly all records match WHERE deleted_at IS NULL
 *   * Cost of scanning < cost of index lookup + table access
 * - EXISTS subquery uses dependent_model_index (Type: ref, efficient)
 *
 * Dependency Hash Subquery:
 * - Table: hd, Type: ref, Key: dependent_model_index ✅
 * - Table: dh, Type: eq_ref, Key: PRIMARY ✅
 * - Both tables using indexes efficiently
 *
 * OPTIMIZATION CONCLUSION:
 * ❌ No additional indexes recommended
 * - Current indexes are optimal for query patterns
 * - Query optimizer is making correct decisions
 * - Performance is excellent (17.95ms for 4k records, 3s for 74k records)
 * - Adding more indexes would slow INSERT/UPDATE without meaningful read improvements
 *
 * RECOMMENDED ONLY IF PERFORMANCE DEGRADES:
 * If future performance becomes an issue (>10s for 74k records), consider:
 * 1. Add change tracking column (composite_hash_needs_update) to reduce rows processed
 * 2. Partition hash_dependents table by dependent_model_type for very large datasets
 * 3. Add covering index if specific query patterns emerge
 */

it('analyzes composite hash update query performance', function () {
    $hashesTable = config('change-detection.tables.hashes', 'hashes');
    $hashDependentsTable = config('change-detection.tables.hash_dependents', 'hash_dependents');
    $connection = DB::connection(config('change-detection.database_connection'));

    // Create test data directly in database
    // Using 1000 records to better simulate production load
    for ($i = 1; $i <= 1000; $i++) {
        // Create a parent hash
        $parentHash = Hash::create([
            'hashable_type' => 'test_model',
            'hashable_id' => $i,
            'attribute_hash' => md5("parent_{$i}"),
            'composite_hash' => md5("parent_{$i}"),
            'has_dependencies_built' => true,
        ]);

        // Create 3 child hashes per parent
        for ($j = 1; $j <= 3; $j++) {
            $childId = ($i * 10) + $j;
            $childHash = Hash::create([
                'hashable_type' => 'test_child',
                'hashable_id' => $childId,
                'attribute_hash' => md5("child_{$childId}"),
                'composite_hash' => md5("child_{$childId}"),
                'has_dependencies_built' => true,
            ]);

            // Create dependency relationship
            HashDependent::create([
                'hash_id' => $childHash->id,
                'dependent_model_type' => 'test_model',
                'dependent_model_id' => $i,
                'relation_name' => 'children',
            ]);
        }
    }

    echo "\n\n=== TABLE STATISTICS ===\n";
    $hashCount = $connection->selectOne("SELECT COUNT(*) as cnt FROM `{$hashesTable}`");
    $depCount = $connection->selectOne("SELECT COUNT(*) as cnt FROM `{$hashDependentsTable}`");
    echo "Rows in {$hashesTable}: {$hashCount->cnt}\n";
    echo "Rows in {$hashDependentsTable}: {$depCount->cnt}\n";

    echo "\n=== EXISTING INDEXES ===\n";
    echo "\nIndexes on {$hashesTable}:\n";
    $hashIndexes = $connection->select("SHOW INDEX FROM `{$hashesTable}`");
    foreach ($hashIndexes as $index) {
        echo sprintf("- %-30s Column: %-25s Non_unique: %d, Cardinality: %s\n",
            $index->Key_name,
            $index->Column_name,
            $index->Non_unique,
            $index->Cardinality ?? 'NULL'
        );
    }

    echo "\nIndexes on {$hashDependentsTable}:\n";
    $depIndexes = $connection->select("SHOW INDEX FROM `{$hashDependentsTable}`");
    foreach ($depIndexes as $index) {
        echo sprintf("- %-30s Column: %-25s Non_unique: %d, Cardinality: %s\n",
            $index->Key_name,
            $index->Column_name,
            $index->Non_unique,
            $index->Cardinality ?? 'NULL'
        );
    }

    // Analyze the EXISTS clause query
    echo "\n\n=== EXPLAIN: Main UPDATE Query (EXISTS clause) ===\n";
    $explainMainQuery = "
        EXPLAIN
        SELECT h.id, h.hashable_type, h.hashable_id
        FROM `{$hashesTable}` h
        WHERE h.deleted_at IS NULL
          AND EXISTS (
            SELECT 1
            FROM `{$hashDependentsTable}` hd
            WHERE hd.dependent_model_type = h.hashable_type
              AND hd.dependent_model_id = h.hashable_id
          )
    ";
    $mainExplain = $connection->select($explainMainQuery);
    foreach ($mainExplain as $row) {
        echo sprintf("Table: %-20s Type: %-10s Key: %-20s Rows: %s\n",
            $row->table,
            $row->type,
            $row->key ?? 'NULL',
            $row->rows
        );
        if (isset($row->Extra)) {
            echo "  Extra: {$row->Extra}\n";
        }
    }

    // Analyze the correlated subquery (dependency hash calculation)
    echo "\n=== EXPLAIN: Dependency Hash Subquery ===\n";
    $explainSubquery = "
        EXPLAIN
        SELECT hd.dependent_model_type, hd.dependent_model_id,
            MD5(GROUP_CONCAT(
                IFNULL(dh.composite_hash, dh.attribute_hash)
                ORDER BY hd.id, dh.hashable_type, dh.hashable_id
                SEPARATOR '|'
            )) as dependency_hash
        FROM `{$hashDependentsTable}` hd
        INNER JOIN `{$hashesTable}` dh
            ON dh.id = hd.hash_id
            AND dh.deleted_at IS NULL
        WHERE hd.dependent_model_type = 'test_weather_station'
          AND hd.dependent_model_id = 1
        GROUP BY hd.dependent_model_type, hd.dependent_model_id
    ";
    $subExplain = $connection->select($explainSubquery);
    foreach ($subExplain as $row) {
        echo sprintf("Table: %-20s Type: %-10s Key: %-20s Rows: %s\n",
            $row->table,
            $row->type,
            $row->key ?? 'NULL',
            $row->rows
        );
        if (isset($row->Extra)) {
            echo "  Extra: {$row->Extra}\n";
        }
    }

    // Test actual query execution time
    echo "\n=== PERFORMANCE TEST ===\n";
    $processor = app(BulkHashProcessor::class);

    $start = microtime(true);
    $updated = $processor->updateParentModelsWithChangedDependencies();
    $duration = microtime(true) - $start;

    echo "Updated {$updated} parent composite hashes\n";
    echo "Execution time: " . round($duration * 1000, 2) . " ms\n";

    expect($updated)->toBeGreaterThan(0);
})->skip('Run manually with: vendor/bin/pest tests/Feature/QueryAnalysisTest.php --filter "analyzes composite hash update query performance"');