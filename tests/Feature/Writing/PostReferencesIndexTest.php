<?php

declare(strict_types=1);

namespace Modules\Forum\Tests\Feature\Writing;

use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Modules\Forum\Application\Contracts\ForumLiveSignalsInterface;
use Modules\Forum\Application\Contracts\ForumUrlsInterface;
use Modules\Forum\Application\Services\ForumWriter;
use Modules\Forum\Application\Services\PostRenderer;
use Modules\Forum\Domain\Entities\Discussion;
use Modules\Forum\Domain\Entities\Post;
use Modules\Forum\Domain\Repositories\DiscussionRepositoryInterface;
use Modules\Forum\Domain\Repositories\PostRepositoryInterface;
use Modules\Forum\Domain\ValueObjects\DiscussionId;
use Modules\Forum\Domain\ValueObjects\DiscussionTitle;
use Modules\Forum\Domain\ValueObjects\PostBody;
use Modules\Forum\Domain\ValueObjects\PostId;
use Modules\Forum\Tests\Support\BuildsForumContent;
use Modules\Forum\Tests\Support\RecordingLiveSignals;
use Tests\Support\Modules\ModuleTestCase;
use Tests\Support\Time\UsesUtcStorage;

/**
 * Every write of a post keeps the posts it points at (by «#N» and by quoting) in step with its
 * body, in the same transaction, and tells the open pages of the posts it points at in its
 * discussion that their reply counts may have changed.
 */
final class PostReferencesIndexTest extends ModuleTestCase
{
    use BuildsForumContent;
    use UsesUtcStorage;

    protected ?string $moduleName = 'forum';

    protected bool $autoEnableModule = true;

    private Discussion $discussion;

    private Post $target;

    private RecordingLiveSignals $signals;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useUtcStorage();
        $this->signals = new RecordingLiveSignals;
        $this->app->instance(ForumLiveSignalsInterface::class, $this->signals);
        [$this->discussion, $this->target] = $this->aDiscussion($this->aCategory());
        $this->discussion = $this->discussionOf($this->discussion->id());
    }

    public function test_a_reply_stores_what_it_references_and_quotes(): void
    {
        $quoted = $this->aReply($this->discussion, '2026-11-10 19:00:00', text: 'Lo que dijo');

        $reply = $this->replyWith([$this->target], quoting: $quoted);

        $this->assertSame(
            [[$quoted->id()->value, 'quote'], [$this->target->id()->value, 'reference']],
            $this->rowsOf($reply),
        );
    }

    public function test_a_reply_awaiting_approval_stores_its_rows(): void
    {
        $reply = $this->replyWith([$this->target], needsApproval: true);

        $this->assertSame([[$this->target->id()->value, 'reference']], $this->rowsOf($reply));
    }

    public function test_an_edit_that_drops_a_reference_drops_its_row(): void
    {
        $other = $this->aReply($this->discussion, '2026-11-10 19:00:00');
        $reply = $this->replyWith([$this->target, $other]);

        $this->writer()->editPost($reply->id(), $this->bodyPointingAt([$other]), null, new DateTimeImmutable('2026-11-10 21:00:00'));

        $this->assertSame([[$other->id()->value, 'reference']], $this->rowsOf($reply));
    }

    public function test_a_purged_post_points_at_nothing(): void
    {
        $reply = $this->replyWith([$this->target]);
        $this->writer()->deletePostByAuthor($reply->id(), new DateTimeImmutable('2026-11-10 21:00:00'));

        $this->writer()->purgeDeletedPost($reply->id(), new DateTimeImmutable('2026-11-20 21:00:00'));

        $this->assertSame([], $this->rowsOf($reply));
    }

    public function test_deleting_the_discussion_leaves_no_rows(): void
    {
        $this->replyWith([$this->target]);

        $this->writer()->deleteDiscussion($this->discussion->id());

        $this->assertSame(0, DB::table('forum_post_references')->count());
    }

    public function test_a_referenced_post_split_away_keeps_its_rows_and_shows_its_new_place(): void
    {
        $moved = $this->aReply($this->discussion, '2026-11-10 19:00:00');
        $reply = $this->replyWith([$moved]);

        $this->writer()->splitDiscussion($this->discussion->id(), [(int) $moved->number()?->value], $this->discussion->categoryId(), new DiscussionTitle('Aparte'), new DateTimeImmutable('2026-11-10 22:00:00'));

        $this->assertSame([[$moved->id()->value, 'reference']], $this->rowsOf($reply));
        $movedNow = app(PostRepositoryInterface::class)->findOrFail($moved->id());
        $newDiscussion = $this->discussionOf($movedNow->discussionId());
        $html = app(PostRenderer::class)->toHtml([app(PostRepositoryInterface::class)->findOrFail($reply->id())->body()], null)[0];
        $this->assertStringContainsString('href="'.e(app(ForumUrlsInterface::class)->discussion($newDiscussion, (int) $movedNow->number()?->value)).'"', $html);
        $this->assertStringContainsString('>#'.$movedNow->number()?->value.'</a>', $html);
    }

    public function test_replying_tells_the_posts_it_points_at(): void
    {
        $this->signals->changed = [];

        $this->replyWith([$this->target]);

        $this->assertContains([$this->discussion->id()->value, 1], $this->signals->changed);
    }

    public function test_editing_tells_the_posts_it_pointed_at_and_points_at(): void
    {
        $other = $this->aReply($this->discussion, '2026-11-10 19:00:00');
        $reply = $this->replyWith([$this->target]);
        $this->signals->changed = [];

        $this->writer()->editPost($reply->id(), $this->bodyPointingAt([$other]), null, new DateTimeImmutable('2026-11-10 21:00:00'));

        $this->assertContains([$this->discussion->id()->value, 1], $this->signals->changed);
        $this->assertContains([$this->discussion->id()->value, (int) $other->number()?->value], $this->signals->changed);
    }

    public function test_hiding_approving_and_deleting_tell_the_posts_it_points_at(): void
    {
        $pending = $this->replyWith([$this->target], needsApproval: true);
        $this->signals->changed = [];
        $this->writer()->approvePost($pending->id(), new DateTimeImmutable('2026-11-10 21:00:00'));
        $this->assertContains([$this->discussion->id()->value, 1], $this->signals->changed);

        $this->signals->changed = [];
        $this->writer()->changePost($pending->id(), static fn (Post $post) => $post->hide());
        $this->assertContains([$this->discussion->id()->value, 1], $this->signals->changed);

        $this->signals->changed = [];
        $this->writer()->changePost($pending->id(), static fn (Post $post) => $post->restore());
        $this->writer()->deletePostByAuthor($pending->id(), new DateTimeImmutable('2026-11-10 22:00:00'));
        $this->assertContains([$this->discussion->id()->value, 1], $this->signals->changed);
    }

    public function test_a_post_in_another_discussion_is_not_told(): void
    {
        [$elsewhere, $farTarget] = $this->aDiscussion($this->aCategory('otra'));
        $this->signals->changed = [];

        $this->replyWith([$farTarget]);

        $this->assertNotContains([$elsewhere->id()->value, 1], $this->signals->changed);
    }

    /**
     * @param  list<Post>  $targets
     */
    private function replyWith(array $targets, ?Post $quoting = null, bool $needsApproval = false): Post
    {
        return $this->writer()->reply($this->discussion->id(), PostId::generate(), null, $this->bodyPointingAt($targets, $quoting), new DateTimeImmutable('2026-11-10 20:00:00'), $needsApproval);
    }

    /**
     * @param  list<Post>  $targets
     */
    private function bodyPointingAt(array $targets, ?Post $quoting = null): PostBody
    {
        $content = [['type' => 'paragraph', 'content' => [
            ['type' => 'text', 'text' => 'Ver '],
            ...array_map(static fn (Post $post): array => ['type' => 'postReference', 'attrs' => ['postId' => $post->id()->value]], $targets),
        ]]];
        if ($quoting instanceof Post) {
            $content[] = ['type' => 'postQuote', 'attrs' => ['postId' => $quoting->id()->value], 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Lo que dijo']]]]];
        }

        return new PostBody(['type' => 'doc', 'content' => $content], 'Ver');
    }

    /**
     * @return list<array{string, string}>
     */
    private function rowsOf(Post $post): array
    {
        return array_values(DB::table('forum_post_references')
            ->where('source_post_id', $post->id()->value)
            ->orderBy('kind')
            ->orderBy('target_post_id')
            ->get(['target_post_id', 'kind'])
            ->map(static fn (object $row): array => [strtolower((string) $row->target_post_id), (string) $row->kind])
            ->all());
    }

    private function discussionOf(DiscussionId $id): Discussion
    {
        $found = app(DiscussionRepositoryInterface::class)->find($id);
        $this->assertInstanceOf(Discussion::class, $found);

        return $found;
    }

    private function writer(): ForumWriter
    {
        return app(ForumWriter::class);
    }
}
