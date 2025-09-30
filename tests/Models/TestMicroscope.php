<?php

namespace Ameax\LaravelChangeDetection\Tests\Models;

use Ameax\LaravelChangeDetection\Contracts\Hashable;
use Ameax\LaravelChangeDetection\Traits\InteractsWithHashes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class TestMicroscope extends Model implements Hashable
{
    use InteractsWithHashes;

    protected $table = 'test_microscopes';

    protected $fillable = [
        'laboratory_facility_id',
        'model',
        'magnification',
        'type',
    ];

    protected $casts = [
        'magnification' => 'decimal:2',
    ];

    public function getHashableAttributes(): array
    {
        return ['model', 'magnification', 'type'];
    }

    public function getHashCompositeDependencies(): array
    {
        return [];
    }

    public function getHashParentRelations(): array
    {
        return ['laboratoryFacility'];
    }

    public function getHashableScope(): ?\Closure
    {
        return null;
    }

    public function getHashableJoins(): array
    {
        return [
            [
                'model' => TestMicroscopeCertificationRegistry::class,
                'join' => fn ($join) => $join->leftJoin(
                    'test_microscope_certification_registry',
                    'test_microscope_certification_registry.microscope_id',
                    '=',
                    'test_microscopes.id'
                ),
                'columns' => ['external_identifier' => 'external_identifier'],
            ],
        ];
    }

    public function laboratoryFacility(): BelongsTo
    {
        return $this->belongsTo(TestLaboratoryFacility::class, 'laboratory_facility_id');
    }

    public function certificationRegistry(): HasOne
    {
        return $this->hasOne(TestMicroscopeCertificationRegistry::class, 'microscope_id');
    }
}
