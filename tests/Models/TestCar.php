<?php

namespace Ameax\LaravelChangeDetection\Tests\Models;

use Ameax\LaravelChangeDetection\Contracts\Hashable;
use Ameax\LaravelChangeDetection\Traits\InteractsWithHashes;
use Illuminate\Database\Eloquent\Model;

class TestCar extends Model implements Hashable
{
    use InteractsWithHashes;

    protected $table = 'test_cars';

    protected $fillable = [
        'model',
        'year',
        'price',
        'is_electric',
        'features',
    ];

    protected $casts = [
        'year' => 'integer',
        'price' => 'decimal:2',
        'is_electric' => 'boolean',
        'features' => 'array',
    ];

    public function getHashableAttributes(): array
    {
        return ['model', 'year', 'price', 'is_electric', 'features'];
    }

    public function getHashCompositeDependencies(): array
    {
        return [];
    }
}