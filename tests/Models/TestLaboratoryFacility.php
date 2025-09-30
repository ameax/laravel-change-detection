<?php

namespace Ameax\LaravelChangeDetection\Tests\Models;

use Ameax\LaravelChangeDetection\Contracts\Hashable;
use Ameax\LaravelChangeDetection\Traits\InteractsWithHashes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TestLaboratoryFacility extends Model implements Hashable
{
    use InteractsWithHashes;

    protected $table = 'test_laboratory_facilities';

    protected $fillable = [
        'name',
        'location',
        'certification_level',
        'facility_code',
    ];

    public function getHashableAttributes(): array
    {
        return ['name', 'location', 'certification_level', 'facility_code'];
    }

    public function getHashCompositeDependencies(): array
    {
        return ['microscopes'];
    }

    public function getHashParentRelations(): array
    {
        return [];
    }

    public function getHashableScope(): ?\Closure
    {
        return null;
    }

    public function microscopes(): HasMany
    {
        return $this->hasMany(TestMicroscope::class, 'laboratory_facility_id');
    }
}
