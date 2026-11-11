<?php

declare(strict_types=1);

namespace Modules\Forum\Tests\Integration\Persistence;

use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Modules\Forum\Application\Contracts\ForumCountersInterface;
use Modules\Forum\Application\Services\ForumWriter;
use Modules\Forum\Domain\Entities\Category;
use Modules\Forum\Domain\Enums\PostStatus;
use Modules\Forum\Domain\Exceptions\DiscussionNotFoundException;
use Modules\Forum\Domain\Exceptions\InvalidPostException;
use Modules\Forum\Domain\Repositories\DiscussionRepositoryInterface;
use Modules\Forum\Domain\Repositories\PostRepositoryInterface;
use Modules\Forum\Domain\ValueObjects\CategoryId;
use Modules\Forum\Domain\ValueObjects\DiscussionId;
use Modules\Forum\Domain\ValueObjects\PostId;
use Modules\Forum\Tests\Support\BuildsForumContent;
use RuntimeException;
use Tests\Support\Modules\ModuleTestCase;
use Tests\Support\Time\UsesUtcStorage;

/**
 * Every write numbers posts under a lock and refreshes the counters of the discussion and its
 * category in the same transaction.
 */
final class ForumWriterTest extends ModuleTestCase
{
    use BuildsForumContent;
    use UsesUtcStorage;

    protected ?string $moduleName = 'forum';

    protected bool $autoEnableModule = true;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useUtcStorage();
    }

    public function test_starting_a_discussion_publishes_its_first_post_as_number_one(): void
    {
        $category = $this->aCategory();
        [$discussion, $post] = $this->aDiscussion($category);

        $this->assertSame(1, $post->number()?->value);
        $this->assertTrue($post->discussionId()->equals($discussion->id()));
        $this->assertEquals($discussion->createdAt(), $post->createdAt());
        $this->assertNotNull(app(DiscussionRepositoryInterface::class)->find($discussion->id()));

        $counters = $this->counters()->discussion($discussion->id());
        $this->assertSame(1, $counters?->postCount);
        $this->assertTrue($counters->lastPostId?->equals($post->id()));
        $this->assertSame('2026-11-10 18:00:00', $counters->lastActivityAt->format('Y-m-d H:i:s'));

        $this->assertCategoryCounters($category, discussions: 1, posts: 1, lastPost: $post->id(), lastActivity: '2026-11-10 18:00:00');
    }

    public function test_replies_take_the_next_numbers(): void
    {
        [$discussion] = $this->aDiscussion($this->aCategory());

        $second = $this->aReply($discussion, '2026-11-10 18:10:00');
        $third = $this->aReply($discussion, '2026-11-10 18:20:00');

        $this->assertSame(2, $second->number()?->value);
        $this->assertSame(3, $third->number()?->value);

        $counters = $this->counters()->discussion($discussion->id());
        $this->assertSame(3, $counters?->postCount);
        $this->assertTrue($counters->lastPostId?->equals($third->id()));
        $this->assertSame('2026-11-10 18:20:00', $counters->lastActivityAt->format('Y-m-d H:i:s'));
    }

    public function test_a_pending_reply_counts_nowhere_until_approved_and_then_takes_the_next_number(): void
    {
        $category = $this->aCategory();
        [$discussion, $first] = $this->aDiscussion($category);

        $pending = $this->aReply($discussion, '2026-11-10 18:10:00', needsApproval: true);
        $visible = $this->aReply($discussion, '2026-11-10 18:20:00');

        $this->assertNull($pending->number());
        $this->assertSame(2, $visible->number()?->value);
        $this->assertSame(2, $this->counters()->discussion($discussion->id())?->postCount);

        $approved = $this->writer()->approvePost($pending->id(), new DateTimeImmutable('2026-11-10 18:30:00'));

        // It reads after the post that was published while it waited, as new activity: readers see no gap
        $this->assertSame(PostStatus::Visible, $approved->status());
        $this->assertSame(3, $approved->number()?->value);
        $counters = $this->counters()->discussion($discussion->id());
        $this->assertSame(3, $counters?->postCount);
        $this->assertTrue($counters->lastPostId?->equals($approved->id()));
        $this->assertSame('2026-11-10 18:30:00', $counters->lastActivityAt->format('Y-m-d H:i:s'));
        $this->assertCategoryCounters($category, discussions: 1, posts: 3, lastPost: $approved->id(), lastActivity: '2026-11-10 18:30:00');
        $this->assertNotNull($first->number());
    }

    public function test_hiding_or_deleting_a_post_leaves_the_counters_and_restoring_brings_it_back(): void
    {
        $category = $this->aCategory();
        [$discussion, $first] = $this->aDiscussion($category);
        $reply = $this->aReply($discussion, '2026-11-10 18:10:00');

        $reply->hide();
        $this->writer()->savePost($reply);

        $counters = $this->counters()->discussion($discussion->id());
        $this->assertSame(1, $counters?->postCount);
        $this->assertTrue($counters->lastPostId?->equals($first->id()));
        $this->assertSame('2026-11-10 18:00:00', $counters->lastActivityAt->format('Y-m-d H:i:s'));
        $this->assertCategoryCounters($category, discussions: 1, posts: 1, lastPost: $first->id(), lastActivity: '2026-11-10 18:00:00');

        $reply->restore();
        $this->writer()->savePost($reply);
        $this->assertSame(2, $this->counters()->discussion($discussion->id())?->postCount);

        $reply->deleteByAuthor(new DateTimeImmutable);
        $this->writer()->savePost($reply);
        $this->assertSame(1, $this->counters()->discussion($discussion->id())->postCount);
        $this->assertSame(2, app(PostRepositoryInterface::class)->findOrFail($reply->id())->number()?->value);
    }

    public function test_a_discussion_without_readable_posts_counts_nowhere_in_its_category(): void
    {
        $category = $this->aCategory();
        [$discussion] = $this->aDiscussion($category, needsApproval: true);

        $counters = $this->counters()->discussion($discussion->id());
        $this->assertSame(0, $counters?->postCount);
        $this->assertNull($counters->lastPostId);
        // With nothing to read yet, its activity is when it started
        $this->assertSame('2026-11-10 18:00:00', $counters->lastActivityAt->format('Y-m-d H:i:s'));
        $this->assertCategoryCounters($category, discussions: 0, posts: 0, lastPost: null, lastActivity: null);
    }

    public function test_a_hidden_discussion_counts_nowhere_in_its_category(): void
    {
        $category = $this->aCategory();
        [$hidden] = $this->aDiscussion($category, '2026-11-10 19:00:00');
        [, $shownPost] = $this->aDiscussion($category, '2026-11-10 18:00:00');

        $hidden->hide();
        $this->writer()->saveDiscussion($hidden);

        $this->assertCategoryCounters($category, discussions: 1, posts: 1, lastPost: $shownPost->id(), lastActivity: '2026-11-10 18:00:00');

        $hidden->unhide();
        $this->writer()->saveDiscussion($hidden);
        $this->assertCategoryCounters($category, discussions: 2, posts: 2, lastPost: null, lastActivity: '2026-11-10 19:00:00');
    }

    public function test_moving_a_discussion_refreshes_both_categories(): void
    {
        $from = $this->aCategory('general');
        $to = $this->aCategory('partidas');
        [$discussion, $post] = $this->aDiscussion($from);
        $this->aReply($discussion, '2026-11-10 18:30:00');

        $discussion->moveTo($to->id());
        $this->writer()->saveDiscussion($discussion);

        $this->assertCategoryCounters($from, discussions: 0, posts: 0, lastPost: null, lastActivity: null);
        $this->assertCategoryCounters($to, discussions: 1, posts: 2, lastPost: null, lastActivity: '2026-11-10 18:30:00');
        $this->assertTrue(app(DiscussionRepositoryInterface::class)->findOrFail($discussion->id())->categoryId()->equals($to->id()));
        $this->assertNotNull($post->number());
    }

    public function test_the_category_shows_its_latest_activity_across_discussions(): void
    {
        $category = $this->aCategory();
        [$older] = $this->aDiscussion($category, '2026-11-10 17:00:00');
        [, $newer] = $this->aDiscussion($category, '2026-11-10 18:00:00');
        $reply = $this->aReply($older, '2026-11-10 19:00:00');

        $this->assertCategoryCounters($category, discussions: 2, posts: 3, lastPost: $reply->id(), lastActivity: '2026-11-10 19:00:00');
        $this->assertNotNull($newer->number());
    }

    public function test_a_pending_post_is_discarded_and_counts_nowhere(): void
    {
        [$discussion] = $this->aDiscussion($this->aCategory());
        $pending = $this->aReply($discussion, '2026-11-10 18:10:00', needsApproval: true);

        $this->writer()->discardPendingPost($pending->id());

        $this->assertNull(app(PostRepositoryInterface::class)->find($pending->id()));
        $this->assertSame(1, $this->counters()->discussion($discussion->id())?->postCount);
    }

    public function test_a_numbered_post_is_never_discarded(): void
    {
        // Removing it would leave a gap in the numbering: it is hidden or deleted instead
        [, $first] = $this->aDiscussion($this->aCategory());

        $this->expectException(InvalidPostException::class);

        $this->writer()->discardPendingPost($first->id());
    }

    public function test_deleting_a_discussion_refreshes_its_category(): void
    {
        $category = $this->aCategory();
        [$discussion] = $this->aDiscussion($category);

        $this->writer()->deleteDiscussion($discussion->id());

        $this->assertNull(app(DiscussionRepositoryInterface::class)->find($discussion->id()));
        $this->assertNull($this->counters()->discussion($discussion->id()));
        $this->assertCategoryCounters($category, discussions: 0, posts: 0, lastPost: null, lastActivity: null);
    }

    public function test_replying_to_a_missing_discussion_fails(): void
    {
        $this->expectException(DiscussionNotFoundException::class);

        $this->writer()->reply(DiscussionId::generate(), PostId::generate(), null, self::aBody('Hola'), new DateTimeImmutable, needsApproval: false);
    }

    public function test_a_failed_write_leaves_nothing_behind(): void
    {
        $category = $this->aCategory();
        [$discussion] = $this->aDiscussion($category);

        DB::listen(function ($query): void {
            if (str_starts_with($query->sql, 'update "forum_categories"')) {
                throw new RuntimeException('Counters failed');
            }
        });

        try {
            $this->aReply($discussion, '2026-11-10 18:10:00');
            $this->fail('The write did not fail.');
        } catch (RuntimeException) {
        }

        $this->assertSame(1, DB::table('forum_posts')->count());
        $this->assertSame(1, $this->counters()->discussion($discussion->id())?->postCount);
    }

    public function test_the_counters_of_a_missing_category_are_none(): void
    {
        $this->assertNull($this->counters()->category(CategoryId::generate()));
    }

    private function assertCategoryCounters(Category $category, int $discussions, int $posts, ?PostId $lastPost, ?string $lastActivity): void
    {
        $counters = $this->counters()->category($category->id());

        $this->assertNotNull($counters);
        $this->assertSame($discussions, $counters->discussionCount, 'discussions');
        $this->assertSame($posts, $counters->postCount, 'posts');
        if ($lastPost instanceof PostId) {
            $this->assertTrue($counters->lastPostId?->equals($lastPost), 'last post');
        }
        if ($lastActivity === null) {
            $this->assertNull($counters->lastPostId);
        }
        $this->assertSame($lastActivity, $counters->lastActivityAt?->format('Y-m-d H:i:s'), 'last activity');
    }

    private function writer(): ForumWriter
    {
        return app(ForumWriter::class);
    }

    private function counters(): ForumCountersInterface
    {
        return app(ForumCountersInterface::class);
    }
}
