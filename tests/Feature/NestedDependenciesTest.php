<?php

declare(strict_types=1);

namespace Ameax\LaravelChangeDetection\Tests\Feature;

use Ameax\LaravelChangeDetection\Models\Hash;
use Ameax\LaravelChangeDetection\Services\ChangeDetector;
use Ameax\LaravelChangeDetection\Services\HashUpdater;
use Ameax\LaravelChangeDetection\Tests\Models\TestArticle;
use Ameax\LaravelChangeDetection\Tests\Models\TestComment;
use Ameax\LaravelChangeDetection\Tests\Models\TestReply;
use Ameax\LaravelChangeDetection\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

class NestedDependenciesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->loadMigrationsFrom(__DIR__.'/../migrations');
        $this->setUpMorphMap();
        $this->artisan('migrate:fresh');
    }

    private function setUpMorphMap(): void
    {
        \Illuminate\Database\Eloquent\Relations\Relation::morphMap([
            'test_article' => TestArticle::class,
            'test_reply' => TestReply::class,
            'test_comment' => TestComment::class,
        ]);
    }

    /** @test */
    public function it_creates_hash_for_article_without_dependencies()
    {
        $article = TestArticle::create([
            'title' => 'Test Article',
            'content' => 'Article content',
            'author' => 'John Doe',
        ]);

        $hash = $article->getCurrentHash();
        $this->assertNotNull($hash);
        $this->assertEquals('test_article', $hash->hashable_type);
        $this->assertEquals($article->id, $hash->hashable_id);
        $this->assertNotNull($hash->attribute_hash);
        $this->assertNotNull($hash->composite_hash);
    }

    /** @test */
    public function it_updates_article_hash_when_reply_is_added()
    {
        $article = TestArticle::create([
            'title' => 'Test Article',
            'content' => 'Article content',
            'author' => 'John Doe',
        ]);

        $originalHash = $article->getCurrentHash()->composite_hash;

        // Add a reply - should trigger article hash update
        TestReply::create([
            'article_id' => $article->id,
            'content' => 'This is a reply',
            'author' => 'Jane Smith',
        ]);

        $article->refresh();
        $newHash = $article->getCurrentHash()->composite_hash;

        $this->assertNotEquals($originalHash, $newHash);
    }

    /** @test */
    public function it_does_not_update_article_hash_when_comment_is_added()
    {
        $article = TestArticle::create([
            'title' => 'Test Article',
            'content' => 'Article content',
            'author' => 'John Doe',
        ]);

        $originalHash = $article->getCurrentHash()->composite_hash;

        // Add a comment - should NOT trigger article hash update
        TestComment::create([
            'article_id' => $article->id,
            'content' => 'This is a comment',
            'author' => 'Jane Smith',
        ]);

        $article->refresh();
        $newHash = $article->getCurrentHash()->composite_hash;

        $this->assertEquals($originalHash, $newHash);
    }

    /** @test */
    public function it_updates_article_hash_when_reply_is_modified()
    {
        $article = TestArticle::create([
            'title' => 'Test Article',
            'content' => 'Article content',
            'author' => 'John Doe',
        ]);

        $reply = TestReply::create([
            'article_id' => $article->id,
            'content' => 'Original reply',
            'author' => 'Jane Smith',
        ]);

        $originalHash = $article->getCurrentHash()->composite_hash;

        // Modify the reply
        $reply->update(['content' => 'Modified reply']);

        $article->refresh();
        $newHash = $article->getCurrentHash()->composite_hash;

        $this->assertNotEquals($originalHash, $newHash);
    }

    /** @test */
    public function it_updates_article_hash_when_reply_is_deleted()
    {
        $article = TestArticle::create([
            'title' => 'Test Article',
            'content' => 'Article content',
            'author' => 'John Doe',
        ]);

        $reply = TestReply::create([
            'article_id' => $article->id,
            'content' => 'Reply to be deleted',
            'author' => 'Jane Smith',
        ]);

        $originalHash = $article->getCurrentHash()->composite_hash;

        // Delete the reply
        $reply->delete();

        $article->refresh();
        $newHash = $article->getCurrentHash()->composite_hash;

        $this->assertNotEquals($originalHash, $newHash);
    }

    /** @test */
    public function it_handles_multiple_replies_correctly()
    {
        $article = TestArticle::create([
            'title' => 'Test Article',
            'content' => 'Article content',
            'author' => 'John Doe',
        ]);

        $originalHash = $article->getCurrentHash()->composite_hash;

        // Add multiple replies
        TestReply::create([
            'article_id' => $article->id,
            'content' => 'First reply',
            'author' => 'Jane Smith',
        ]);

        $firstReplyHash = $article->fresh()->getCurrentHash()->composite_hash;
        $this->assertNotEquals($originalHash, $firstReplyHash);

        TestReply::create([
            'article_id' => $article->id,
            'content' => 'Second reply',
            'author' => 'Bob Johnson',
        ]);

        $secondReplyHash = $article->fresh()->getCurrentHash()->composite_hash;
        $this->assertNotEquals($firstReplyHash, $secondReplyHash);

        // Verify deterministic ordering - same replies should produce same hash
        $article->forceHashUpdate();
        $finalHash = $article->getCurrentHash()->composite_hash;
        $this->assertEquals($secondReplyHash, $finalHash);
    }

    /** @test */
    public function it_detects_changed_articles_efficiently()
    {
        $changeDetector = app(ChangeDetector::class);

        // Create articles with replies
        $article1 = TestArticle::create([
            'title' => 'Article 1',
            'content' => 'Content 1',
            'author' => 'Author 1',
        ]);

        $article2 = TestArticle::create([
            'title' => 'Article 2',
            'content' => 'Content 2',
            'author' => 'Author 2',
        ]);

        TestReply::create([
            'article_id' => $article1->id,
            'content' => 'Reply 1',
            'author' => 'Replier 1',
        ]);

        // No articles should be changed at this point
        $changedIds = $changeDetector->detectChangedModelIds(TestArticle::class);
        $this->assertEmpty($changedIds);

        // Modify article 1 directly
        $article1->update(['title' => 'Modified Article 1']);

        $changedIds = $changeDetector->detectChangedModelIds(TestArticle::class);
        $this->assertContains($article1->id, $changedIds);
        $this->assertNotContains($article2->id, $changedIds);

        // Add reply to article 2
        TestReply::create([
            'article_id' => $article2->id,
            'content' => 'Reply 2',
            'author' => 'Replier 2',
        ]);

        $changedIds = $changeDetector->detectChangedModelIds(TestArticle::class);
        $this->assertContains($article1->id, $changedIds);
        $this->assertContains($article2->id, $changedIds);
    }

    /** @test */
    public function it_handles_hash_dependencies_correctly()
    {
        $article = TestArticle::create([
            'title' => 'Test Article',
            'content' => 'Article content',
            'author' => 'John Doe',
        ]);

        $reply = TestReply::create([
            'article_id' => $article->id,
            'content' => 'Test reply',
            'author' => 'Jane Smith',
        ]);

        // Check that hash_dependents table is populated correctly
        $articleHash = $article->getCurrentHash();
        $replyHash = $reply->getCurrentHash();

        $dependents = $replyHash->dependents;
        $this->assertCount(1, $dependents);
        $this->assertEquals('test_article', $dependents->first()->dependent_model_type);
        $this->assertEquals($article->id, $dependents->first()->dependent_model_id);
    }

    /** @test */
    public function it_performs_bulk_operations_efficiently()
    {
        $hashUpdater = app(HashUpdater::class);

        // Create multiple articles
        $articles = collect();
        for ($i = 1; $i <= 100; $i++) {
            $articles->push(TestArticle::create([
                'title' => "Article {$i}",
                'content' => "Content {$i}",
                'author' => "Author {$i}",
            ]));
        }

        $articleIds = $articles->pluck('id')->toArray();

        // Bulk update should be efficient
        $start = microtime(true);
        $updatedIds = $hashUpdater->updateHashesBulk(TestArticle::class, $articleIds);
        $duration = microtime(true) - $start;

        $this->assertCount(100, $updatedIds);
        $this->assertLessThan(5, $duration); // Should complete in under 5 seconds

        // Verify all hashes were created
        $hashCount = Hash::where('hashable_type', 'test_article')
            ->whereIn('hashable_id', $articleIds)
            ->count();

        $this->assertEquals(100, $hashCount);
    }
}
