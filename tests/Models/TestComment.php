<?php

namespace Ameax\LaravelChangeDetection\Tests\Models;

use Ameax\LaravelChangeDetection\Contracts\Hashable;
use Ameax\LaravelChangeDetection\Traits\InteractsWithHashes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TestComment extends Model implements Hashable
{
    use InteractsWithHashes;

    protected $table = 'test_comments';

    protected $fillable = [
        'post_id',
        'user_id',
        'content',
    ];

    public function getHashableAttributes(): array
    {
        return ['content'];
    }

    public function getHashCompositeDependencies(): array
    {
        return [];
    }

    public function post(): BelongsTo
    {
        return $this->belongsTo(TestPost::class, 'post_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(TestUser::class, 'user_id');
    }
}