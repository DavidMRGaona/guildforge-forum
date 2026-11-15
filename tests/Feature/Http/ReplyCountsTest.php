<?php

declare(strict_types=1);

namespace Modules\Forum\Tests\Feature\Http;

use App\Application\Authorization\Services\AuthorizationServiceInterface;
use App\Infrastructure\Persistence\Eloquent\Models\RoleModel;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Modules\Forum\Application\Services\ForumWriter;
use Modules\Forum\Domain\Entities\Discussion;
use Modules\Forum\Domain\Entities\Post;
use Modules\Forum\Domain\Repositories\DiscussionRepositoryInterface;
use Modules\Forum\Domain\ValueObjects\CategoryAudience;
use Modules\Forum\Domain\ValueObjects\DiscussionTitle;
use Modules\Forum\Domain\ValueObjects\PostBody;
use Modules\Forum\Domain\ValueObjects\PostId;
use Modules\Forum\Tests\Support\BuildsForumContent;
use Tests\Support\Modules\ModuleTestCase;
use Tests\Support\Time\UsesUtcStorage;

/**
 * Each post says how many visible posts reply to it (point at it with «#N» or quote it), as
 * far as the reader may read them.
 */
final class ReplyCountsTest extends ModuleTestCase
{
    use BuildsForumContent;
    use UsesUtcStorage;

    protected ?string $moduleName = 'forum';

    protected bool $autoEnableModule = true;

    private Discussion $discussion;

    private Post $first;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useUtcStorage();
        [$this->discussion, $this->first] = $this->aDiscussion($this->aCategory());
    }

    public function test_a_reference_and_a_quote_from_two_posts_count_two(): void
    {
        $this->replyPointing(reference: true);
        $this->replyPointing(quote: true);

        $this->assertReplyCount(2);
    }

    public function test_a_post_that_references_and_quotes_counts_once(): void
    {
        $this->replyPointing(reference: true, quote: true, twice: true);

        $this->assertReplyCount(1);
    }

    public function test_hidden_pending_and_deleted_replies_do_not_count(): void
    {
        $hidden = $this->replyPointing(reference: true);
        app(ForumWriter::class)->changePost($hidden->id(), static fn (Post $post) => $post->hide());
        $this->replyPointing(reference: true, needsApproval: true);
        $deleted = $this->replyPointing(reference: true);
        app(ForumWriter::class)->deletePostByAuthor($deleted->id(), new DateTimeImmutable('2026-11-10 21:00:00'));

        $this->assertReplyCount(0);
    }

    public function test_a_reply_moved_where_the_reader_cannot_read_does_not_count(): void
    {
        $this->moveAReplyToAPrivateCategory();

        $this->assertReplyCount(0);
    }

    public function test_a_reply_moved_where_the_reader_may_read_still_counts(): void
    {
        $role = $this->moveAReplyToAPrivateCategory();
        $keeper = $this->anAccount();
        $keeper->roles()->attach($role->id);
        app(AuthorizationServiceInterface::class)->invalidateCacheFor($keeper);

        $this->actingAs($keeper)->get($this->discussionUrl())
            ->assertInertia(fn (AssertableInertia $page) => $page->where('posts.0.replyCount', 1)->etc());
    }

    public function test_the_counts_of_a_page_take_one_query(): void
    {
        $this->replyPointing(reference: true);
        $this->replyPointing(quote: true);

        DB::enableQueryLog();
        $this->get($this->discussionUrl())->assertOk();
        $queries = array_filter(DB::getQueryLog(), static fn (array $query): bool => str_contains($query['query'], 'forum_post_references'));
        DB::disableQueryLog();

        $this->assertCount(1, $queries);
    }

    public function test_a_post_with_replies_says_where_to_list_them(): void
    {
        $this->replyPointing(reference: true);

        $this->get($this->discussionUrl())
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('posts.0.repliesUrl', route('forum.posts.replies', ['post' => $this->first->id()->value]))
                ->where('posts.1.replyCount', 0)
                ->where('posts.1.repliesUrl', null)
                ->etc());
    }

    public function test_live_updates_carry_the_counts(): void
    {
        $this->replyPointing(reference: true);

        $this->getJson(route('forum.discussions.updates', ['discussion' => $this->discussion->id()->value, 'despues' => 0]))
            ->assertOk()
            ->assertJsonPath('posts.0.replyCount', 1);
    }

    private function replyPointing(bool $reference = false, bool $quote = false, bool $twice = false, bool $needsApproval = false): Post
    {
        $target = $this->first->id()->value;
        $content = [['type' => 'paragraph', 'content' => [
            ['type' => 'text', 'text' => 'Ver '],
            ...($reference ? [['type' => 'postReference', 'attrs' => ['postId' => $target]]] : []),
            ...($reference && $twice ? [['type' => 'text', 'text' => ' y '], ['type' => 'postReference', 'attrs' => ['postId' => $target]]] : []),
        ]]];
        if ($quote) {
            $content[] = ['type' => 'postQuote', 'attrs' => ['postId' => $target], 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Primer mensaje']]]]];
        }

        return app(ForumWriter::class)->reply($this->discussion->id(), PostId::generate(), null, new PostBody(['type' => 'doc', 'content' => $content], 'Ver'), new DateTimeImmutable('2026-11-10 20:00:00'), $needsApproval);
    }

    private function moveAReplyToAPrivateCategory(): RoleModel
    {
        $role = RoleModel::query()->create(['name' => 'keepers', 'display_name' => 'Keepers']);
        $private = $this->aCategoryFor(CategoryAudience::roles([$role->id]), 'privada');
        $reply = $this->replyPointing(reference: true);
        app(ForumWriter::class)->splitDiscussion($this->discussion->id(), [(int) $reply->number()?->value], $private->id(), new DiscussionTitle('Aparte'), new DateTimeImmutable('2026-11-10 22:00:00'));

        return $role;
    }

    private function assertReplyCount(int $expected): void
    {
        $this->get($this->discussionUrl())
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('posts.0.replyCount', $expected)->etc());
    }

    private function discussionUrl(): string
    {
        $discussion = app(DiscussionRepositoryInterface::class)->find($this->discussion->id());
        $this->assertInstanceOf(Discussion::class, $discussion);

        return '/foro/t/'.$discussion->number().'-'.str($discussion->title()->value)->slug();
    }
}
