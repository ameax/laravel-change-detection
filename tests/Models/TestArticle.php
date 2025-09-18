<?php

declare(strict_types=1);

namespace Ameax\LaravelChangeDetection\Tests\Models;

use Ameax\LaravelChangeDetection\Contracts\Hashable;
use Ameax\LaravelChangeDetection\Traits\InteractsWithHashes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TestArticle extends Model implements Hashable
{
    use InteractsWithHashes;

    protected $table = 'test_articles';

    protected $fillable = [
        'title',
        'content',
        'author',
        'published_at',
    ];

    protected $casts = [
        'published_at' => 'datetime',
    ];

    public function getHashableAttributes(): array
    {
        return ['title', 'content', 'author'];
    }

    public function getHashCompositeDependencies(): array
    {
        // Direct dependency to replies - no need to go through comments
        return ['replies'];
    }

    public function replies(): HasMany
    {
        return $this->hasMany(TestReply::class, 'article_id');
    }

    public function comments(): HasMany
    {
        // Normal relation, but not hash-relevant
        return $this->hasMany(TestComment::class, 'article_id');
    }
}
