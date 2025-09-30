<?php

namespace Ameax\LaravelChangeDetection\Tests\Feature;

use Ameax\LaravelChangeDetection\Models\Hash;
use Ameax\LaravelChangeDetection\Services\MySQLHashCalculator;
use Ameax\LaravelChangeDetection\Tests\Models\TestLaboratoryFacility;
use Ameax\LaravelChangeDetection\Tests\Models\TestMicroscope;
use Ameax\LaravelChangeDetection\Tests\Models\TestMicroscopeCertificationRegistry;
use Ameax\LaravelChangeDetection\Tests\Models\TestMicroscopeManufacturerRegistry;
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

    public function test_microscope_hash_includes_multiple_joined_external_identifiers(): void
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

        // Create manufacturer registry with external identifier
        TestMicroscopeManufacturerRegistry::create([
            'microscope_id' => $microscope->id,
            'manufacturer_external_id' => 'MFR-ZEISS-001',
            'manufacturer_name' => 'Carl Zeiss AG',
        ]);

        // Calculate hash using MySQLHashCalculator
        $calculator = new MySQLHashCalculator;
        $attributeHash = $calculator->calculateAttributeHash($microscope);

        // Verify hash is calculated
        $this->assertNotEmpty($attributeHash);

        // Create second microscope with same model attributes but different certification
        $microscope2 = TestMicroscope::create([
            'laboratory_facility_id' => $facility->id,
            'model' => 'Zeiss Axio',
            'magnification' => 1000.00,
            'type' => 'optical',
        ]);

        TestMicroscopeCertificationRegistry::create([
            'microscope_id' => $microscope2->id,
            'external_identifier' => 'EXT-CERT-99999',
            'certification_date' => '2025-01-15',
        ]);

        TestMicroscopeManufacturerRegistry::create([
            'microscope_id' => $microscope2->id,
            'manufacturer_external_id' => 'MFR-ZEISS-002',
            'manufacturer_name' => 'Carl Zeiss AG',
        ]);

        $attributeHash2 = $calculator->calculateAttributeHash($microscope2);

        // Hashes should be different because certification external_identifier is different
        $this->assertNotEquals($attributeHash, $attributeHash2);

        // Create third microscope with same model and certification but different manufacturer
        $microscope3 = TestMicroscope::create([
            'laboratory_facility_id' => $facility->id,
            'model' => 'Zeiss Axio',
            'magnification' => 1000.00,
            'type' => 'optical',
        ]);

        TestMicroscopeCertificationRegistry::create([
            'microscope_id' => $microscope3->id,
            'external_identifier' => 'EXT-CERT-33333',
            'certification_date' => '2025-01-15',
        ]);

        TestMicroscopeManufacturerRegistry::create([
            'microscope_id' => $microscope3->id,
            'manufacturer_external_id' => 'MFR-NIKON-002',
            'manufacturer_name' => 'Nikon Corporation',
        ]);

        $attributeHash3 = $calculator->calculateAttributeHash($microscope3);

        // Hashes should be different because manufacturer_external_id is different
        $this->assertNotEquals($attributeHash, $attributeHash3);
        $this->assertNotEquals($attributeHash2, $attributeHash3);
    }

    public function test_microscope_hash_handles_partial_and_null_joined_data(): void
    {
        // Create laboratory facility
        $facility = TestLaboratoryFacility::create([
            'name' => 'Basic Lab',
            'location' => 'Berlin',
            'certification_level' => 'ISO-9001',
            'facility_code' => 'BL-001',
        ]);

        // Test 1: Microscope with no join data at all
        $microscope1 = TestMicroscope::create([
            'laboratory_facility_id' => $facility->id,
            'model' => 'Nikon Eclipse',
            'magnification' => 500.00,
            'type' => 'optical',
        ]);

        $calculator = new MySQLHashCalculator;
        $hash1 = $calculator->calculateAttributeHash($microscope1);
        $this->assertNotEmpty($hash1);

        // Test 2: Microscope with only certification, no manufacturer
        $microscope2 = TestMicroscope::create([
            'laboratory_facility_id' => $facility->id,
            'model' => 'Nikon Eclipse',
            'magnification' => 500.00,
            'type' => 'optical',
        ]);

        TestMicroscopeCertificationRegistry::create([
            'microscope_id' => $microscope2->id,
            'external_identifier' => 'EXT-CERT-PARTIAL',
            'certification_date' => '2025-01-15',
        ]);

        $hash2 = $calculator->calculateAttributeHash($microscope2);
        $this->assertNotEmpty($hash2);
        $this->assertNotEquals($hash1, $hash2); // Should differ due to certification

        // Test 3: Microscope with only manufacturer, no certification
        $microscope3 = TestMicroscope::create([
            'laboratory_facility_id' => $facility->id,
            'model' => 'Nikon Eclipse',
            'magnification' => 500.00,
            'type' => 'optical',
        ]);

        TestMicroscopeManufacturerRegistry::create([
            'microscope_id' => $microscope3->id,
            'manufacturer_external_id' => 'MFR-NIKON-PARTIAL',
            'manufacturer_name' => 'Nikon Corp',
        ]);

        $hash3 = $calculator->calculateAttributeHash($microscope3);
        $this->assertNotEmpty($hash3);
        $this->assertNotEquals($hash1, $hash3); // Should differ due to manufacturer
        $this->assertNotEquals($hash2, $hash3); // Should differ from each other

        // Test 4: Microscope with both joins populated
        $microscope4 = TestMicroscope::create([
            'laboratory_facility_id' => $facility->id,
            'model' => 'Nikon Eclipse',
            'magnification' => 500.00,
            'type' => 'optical',
        ]);

        TestMicroscopeCertificationRegistry::create([
            'microscope_id' => $microscope4->id,
            'external_identifier' => 'EXT-CERT-FULL',
            'certification_date' => '2025-01-15',
        ]);

        TestMicroscopeManufacturerRegistry::create([
            'microscope_id' => $microscope4->id,
            'manufacturer_external_id' => 'MFR-NIKON-FULL',
            'manufacturer_name' => 'Nikon Corp',
        ]);

        $hash4 = $calculator->calculateAttributeHash($microscope4);
        $this->assertNotEmpty($hash4);
        // All four should have different hashes
        $this->assertNotEquals($hash1, $hash4);
        $this->assertNotEquals($hash2, $hash4);
        $this->assertNotEquals($hash3, $hash4);
    }

    public function test_bulk_hash_calculation_with_multiple_joins(): void
    {
        // Create laboratory facility
        $facility = TestLaboratoryFacility::create([
            'name' => 'Research Center',
            'location' => 'Hamburg',
            'certification_level' => 'ISO-9001',
            'facility_code' => 'RC-001',
        ]);

        // Create microscope 1: Full data in both joins
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

        TestMicroscopeManufacturerRegistry::create([
            'microscope_id' => $microscope1->id,
            'manufacturer_external_id' => 'MFR-A-001',
            'manufacturer_name' => 'Manufacturer A',
        ]);

        // Create microscope 2: Different data in both joins
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

        TestMicroscopeManufacturerRegistry::create([
            'microscope_id' => $microscope2->id,
            'manufacturer_external_id' => 'MFR-B-002',
            'manufacturer_name' => 'Manufacturer B',
        ]);

        // Create microscope 3: Only certification, no manufacturer
        $microscope3 = TestMicroscope::create([
            'laboratory_facility_id' => $facility->id,
            'model' => 'Model C',
            'magnification' => 1500.00,
            'type' => 'optical',
        ]);

        TestMicroscopeCertificationRegistry::create([
            'microscope_id' => $microscope3->id,
            'external_identifier' => 'EXT-C-003',
            'certification_date' => '2025-01-17',
        ]);

        // Calculate bulk hashes
        $calculator = new MySQLHashCalculator;
        $hashes = $calculator->calculateAttributeHashBulk(
            TestMicroscope::class,
            [$microscope1->id, $microscope2->id, $microscope3->id]
        );

        $this->assertCount(3, $hashes);
        $this->assertArrayHasKey($microscope1->id, $hashes);
        $this->assertArrayHasKey($microscope2->id, $hashes);
        $this->assertArrayHasKey($microscope3->id, $hashes);

        // All three should have different hashes
        $this->assertNotEquals($hashes[$microscope1->id], $hashes[$microscope2->id]);
        $this->assertNotEquals($hashes[$microscope1->id], $hashes[$microscope3->id]);
        $this->assertNotEquals($hashes[$microscope2->id], $hashes[$microscope3->id]);
    }

    public function test_joined_columns_are_sorted_alphabetically_at_end(): void
    {
        // This test verifies that joined columns appear after model attributes
        // and are sorted alphabetically by their alias:
        // Order: model attributes (magnification, model, type) | joined (external_identifier, manufacturer_external_id)

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

        TestMicroscopeManufacturerRegistry::create([
            'microscope_id' => $microscope->id,
            'manufacturer_external_id' => 'MFR-TEST-001',
            'manufacturer_name' => 'Test Manufacturer',
        ]);

        $calculator = new MySQLHashCalculator;
        $hash1 = $calculator->calculateAttributeHash($microscope);

        // Update the certification external identifier (alphabetically first in joins)
        $microscope->certificationRegistry()->update([
            'external_identifier' => 'TEST-EXT-999',
        ]);

        $hash2 = $calculator->calculateAttributeHash($microscope->fresh());
        $this->assertNotEquals($hash1, $hash2);

        // Update the manufacturer external identifier (alphabetically second in joins)
        $microscope->manufacturerRegistry()->update([
            'manufacturer_external_id' => 'MFR-TEST-999',
        ]);

        $hash3 = $calculator->calculateAttributeHash($microscope->fresh());
        $this->assertNotEquals($hash2, $hash3);
        $this->assertNotEquals($hash1, $hash3);
    }
}
