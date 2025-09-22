<?php

namespace Ameax\LaravelChangeDetection\Tests\Models;

use Ameax\LaravelChangeDetection\Contracts\Hashable;
use Ameax\LaravelChangeDetection\Traits\InteractsWithHashes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TestUser extends Model implements Hashable
{
    use InteractsWithHashes;

    protected $table = 'test_users';

    protected $fillable = [
        'name',
        'email',
    ];

    public function getHashableAttributes(): array
    {
        return ['name', 'email'];
    }

    public function getHashCompositeDependencies(): array
    {
        return ['posts'];
    }

    public function posts(): HasMany
    {
        return $this->hasMany(TestPost::class, 'user_id');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(TestComment::class, 'user_id');
    }
}
