<?php

namespace Ameax\LaravelChangeDetection\Tests\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TestMicroscopeCertificationRegistry extends Model
{
    protected $table = 'test_microscope_certification_registry';

    protected $fillable = [
        'microscope_id',
        'external_identifier',
        'certification_date',
    ];

    protected $casts = [
        'certification_date' => 'date',
    ];

    public function microscope(): BelongsTo
    {
        return $this->belongsTo(TestMicroscope::class, 'microscope_id');
    }
}
