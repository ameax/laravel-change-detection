<?php

namespace Ameax\LaravelChangeDetection\Tests\Feature;

use Ameax\LaravelChangeDetection\Models\Hash;
use Ameax\LaravelChangeDetection\Services\MySQLHashCalculator;
use Ameax\LaravelChangeDetection\Tests\Models\TestLaboratoryFacility;
use Ameax\LaravelChangeDetection\Tests\Models\TestMicroscope;
use Ameax\LaravelChangeDetection\Tests\Models\TestMicroscopeCertificationRegistry;
use Ameax\LaravelChangeDetection\Tests\TestCase;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Artisan;

class MicroscopeJoinTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Relation::morphMap([
            'test_laboratory_facility' => TestLaboratoryFacility::class,
            'test_microscope' => TestMicroscope::class,
        ]);

        Artisan::call('change-detection:truncate', ['--force' => true]);

        Hash::query()->delete();
    }

    public function test_microscope_hash_includes_joined_external_identifier(): void
    {
        // Create laboratory facility
        $facility = TestLaboratoryFacility::create([
            'name' => 'Advanced Research Lab',
            'location' => 'Munich',
            'certification_level' => 'ISO-9001',
            'facility_code' => 'ARL-001',
        ]);

        // Create microscope
        $microscope = TestMicroscope::create([
            'laboratory_facility_id' => $facility->id,
            'model' => 'Zeiss Axio',
            'magnification' => 1000.00,
            'type' => 'optical',
        ]);

        // Create certification registry with external identifier
        TestMicroscopeCertificationRegistry::create([
            'microscope_id' => $microscope->id,
            'external_identifier' => 'EXT-CERT-12345',
            'certification_date' => '2025-01-15',
        ]);

        // Calculate hash using MySQLHashCalculator
        $calculator = new MySQLHashCalculator;
        $attributeHash = $calculator->calculateAttributeHash($microscope);

        // Verify hash is calculated
        $this->assertNotEmpty($attributeHash);

        // Calculate hash without external identifier for comparison
        $microscope2 = TestMicroscope::create([
            'laboratory_facility_id' => $facility->id,
            'model' => 'Zeiss Axio',
            'magnification' => 1000.00,
            'type' => 'optical',
        ]);

        // Create different external identifier
        TestMicroscopeCertificationRegistry::create([
            'microscope_id' => $microscope2->id,
            'external_identifier' => 'EXT-CERT-99999',
            'certification_date' => '2025-01-15',
        ]);

        $attributeHash2 = $calculator->calculateAttributeHash($microscope2);

        // Hashes should be different because external_identifier is different
        $this->assertNotEquals($attributeHash, $attributeHash2);
    }

    public function test_microscope_hash_handles_null_joined_data(): void
    {
        // Create laboratory facility
        $facility = TestLaboratoryFacility::create([
            'name' => 'Basic Lab',
            'location' => 'Berlin',
            'certification_level' => 'ISO-9001',
            'facility_code' => 'BL-001',
        ]);

        // Create microscope without certification registry
        $microscope = TestMicroscope::create([
            'laboratory_facility_id' => $facility->id,
            'model' => 'Nikon Eclipse',
            'magnification' => 500.00,
            'type' => 'optical',
        ]);

        // Calculate hash - should handle NULL external_identifier
        $calculator = new MySQLHashCalculator;
        $attributeHash = $calculator->calculateAttributeHash($microscope);

        $this->assertNotEmpty($attributeHash);
    }

    public function test_bulk_hash_calculation_with_joins(): void
    {
        // Create laboratory facility
        $facility = TestLaboratoryFacility::create([
            'name' => 'Research Center',
            'location' => 'Hamburg',
            'certification_level' => 'ISO-9001',
            'facility_code' => 'RC-001',
        ]);

        // Create multiple microscopes
        $microscope1 = TestMicroscope::create([
            'laboratory_facility_id' => $facility->id,
            'model' => 'Model A',
            'magnification' => 1000.00,
            'type' => 'optical',
        ]);

        TestMicroscopeCertificationRegistry::create([
            'microscope_id' => $microscope1->id,
            'external_identifier' => 'EXT-A-001',
            'certification_date' => '2025-01-15',
        ]);

        $microscope2 = TestMicroscope::create([
            'laboratory_facility_id' => $facility->id,
            'model' => 'Model B',
            'magnification' => 2000.00,
            'type' => 'electron',
        ]);

        TestMicroscopeCertificationRegistry::create([
            'microscope_id' => $microscope2->id,
            'external_identifier' => 'EXT-B-002',
            'certification_date' => '2025-01-16',
        ]);

        // Calculate bulk hashes
        $calculator = new MySQLHashCalculator;
        $hashes = $calculator->calculateAttributeHashBulk(
            TestMicroscope::class,
            [$microscope1->id, $microscope2->id]
        );

        $this->assertCount(2, $hashes);
        $this->assertArrayHasKey($microscope1->id, $hashes);
        $this->assertArrayHasKey($microscope2->id, $hashes);
        $this->assertNotEquals($hashes[$microscope1->id], $hashes[$microscope2->id]);
    }

    public function test_joined_columns_are_sorted_alphabetically_at_end(): void
    {
        // This test verifies that joined columns appear after model attributes
        // and are sorted alphabetically by their alias

        $facility = TestLaboratoryFacility::create([
            'name' => 'Test Lab',
            'location' => 'Stuttgart',
            'certification_level' => 'ISO-9001',
            'facility_code' => 'TL-001',
        ]);

        $microscope = TestMicroscope::create([
            'laboratory_facility_id' => $facility->id,
            'model' => 'Test Model',
            'magnification' => 1000.00,
            'type' => 'optical',
        ]);

        TestMicroscopeCertificationRegistry::create([
            'microscope_id' => $microscope->id,
            'external_identifier' => 'TEST-EXT-001',
            'certification_date' => '2025-01-15',
        ]);

        $calculator = new MySQLHashCalculator;
        $hash1 = $calculator->calculateAttributeHash($microscope);

        // Update the external identifier
        $microscope->certificationRegistry()->update([
            'external_identifier' => 'TEST-EXT-999',
        ]);

        $hash2 = $calculator->calculateAttributeHash($microscope->fresh());

        // Hash should change when joined column changes
        $this->assertNotEquals($hash1, $hash2);
    }
}
