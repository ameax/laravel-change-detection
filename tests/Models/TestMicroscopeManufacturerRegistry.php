<?php

namespace Ameax\LaravelChangeDetection\Tests\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TestMicroscopeManufacturerRegistry extends Model
{
    protected $table = 'test_microscope_manufacturer_registry';

    protected $fillable = [
        'microscope_id',
        'manufacturer_external_id',
        'manufacturer_name',
    ];

    public function microscope(): BelongsTo
    {
        return $this->belongsTo(TestMicroscope::class, 'microscope_id');
    }
}
