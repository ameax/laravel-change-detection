<?php

namespace Ameax\LaravelChangeDetection\Tests\Models;

use Ameax\LaravelChangeDetection\Contracts\Hashable;
use Ameax\LaravelChangeDetection\Traits\InteractsWithHashes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TestPost extends Model implements Hashable
{
    use InteractsWithHashes;

    protected $table = 'test_posts';

    protected $fillable = [
        'user_id',
        'title',
        'content',
        'status',
    ];

    public function getHashableAttributes(): array
    {
        return ['title', 'content', 'status'];
    }

    public function getHashCompositeDependencies(): array
    {
        return ['comments'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(TestUser::class, 'user_id');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(TestComment::class, 'post_id');
    }
}