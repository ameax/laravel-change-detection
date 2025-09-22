<?php

namespace Ameax\LaravelChangeDetection\Tests\Models;

use Ameax\LaravelChangeDetection\Contracts\Hashable;
use Ameax\LaravelChangeDetection\Traits\InteractsWithHashes;
use Illuminate\Database\Eloquent\Model;

class TestModel extends Model implements Hashable
{
    use InteractsWithHashes;

    protected $table = 'test_models';

    protected $fillable = [
        'name',
        'description',
        'status',
    ];

    public function getHashableAttributes(): array
    {
        return ['name', 'description', 'status'];
    }

    public function getHashCompositeDependencies(): array
    {
        return [];
    }
}