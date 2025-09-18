<?php

declare(strict_types=1);

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
        'article_id',
        'content',
        'author',
        'created_at',
    ];

    public function getHashableAttributes(): array
    {
        return ['content', 'author'];
    }

    public function getHashCompositeDependencies(): array
    {
        // Optional: Could have dependencies if needed
        // For now, leaf node
        return [];
    }

    public function article(): BelongsTo
    {
        return $this->belongsTo(TestArticle::class, 'article_id');
    }
}
