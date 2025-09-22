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
    ];

    protected $casts = [
        'birthday' => 'integer',
        'group' => 'string',
        'features' => 'array',
    ];

    public function scopeTypeFilter($query, $type)
    {
        return $query->where('type', $type);
    }

    public function getHashableAttributes(): array
    {
        return ['type', 'birthday', 'group', 'features'];
    }

    public function getHashCompositeDependencies(): array
    {
        return [];
    }
}
