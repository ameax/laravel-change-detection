<?php

namespace Ameax\LaravelChangeDetection\Tests\Models;

use Ameax\LaravelChangeDetection\Contracts\Hashable;
use Ameax\LaravelChangeDetection\Traits\InteractsWithHashes;
use Illuminate\Database\Eloquent\Model;

class TestAnimal extends Model implements Hashable
{
    use InteractsWithHashes;

    protected $table = 'test_animals';

    protected $fillable = [
        'type',
        'birthday',
        'group',
        'features',
        'weight',
    ];

    protected $casts = [
        'birthday' => 'integer',
        'group' => 'string',
        'features' => 'array',
        'weight' => 'float',
    ];

    public function scopeTypeFilter($query, $type)
    {
        return $query->where('type', $type);
    }

    public function getHashableScope(): ?\Closure
    {
        return function ($query) {
            $query->where('weight', '>', 3);
        };
    }

    public function getHashableAttributes(): array
    {
        return ['type', 'birthday', 'group', 'features', 'weight'];
    }

    public function getHashCompositeDependencies(): array
    {
        return [];
    }
}
