<?php

declare(strict_types=1);

namespace Modules\Forum\Tests\Integration\Application;

use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use Carbon\CarbonImmutable;
use Modules\Forum\Application\Contracts\ForumAccessInterface;
use Modules\Forum\Application\Contracts\ForumNoticesInterface;
use Modules\Forum\Application\Contracts\SystemAccountInterface;
use Modules\Forum\Application\Services\ForumWriter;
use Modules\Forum\Application\Services\PostLikeService;
use Modules\Forum\Domain\Entities\Discussion;
use Modules\Forum\Domain\Entities\Post;
use Modules\Forum\Domain\Exceptions\ForumActionDeniedException;
use Modules\Forum\Domain\Exceptions\PostNotFoundException;
use Modules\Forum\Domain\ValueObjects\PostId;
use Modules\Forum\Tests\Support\BuildsForumContent;
use Modules\Forum\Tests\Support\FakeForumAccess;
use Modules\Forum\Tests\Support\RecordingForumNotices;
use Tests\Support\Modules\ModuleTestCase;
use Tests\Support\Time\UsesUtcStorage;

/**
 * Likes: one per person and post, never on one's own post or the system account's, only on
 * posts the person reads; the author hears about each new like once.
 */
final class PostLikeServiceTest extends ModuleTestCase
{
    use BuildsForumContent;
    use UsesUtcStorage;

    protected ?string $moduleName = 'forum';

    protected bool $autoEnableModule = true;

    private FakeForumAccess $access;

    private RecordingForumNotices $notices;

    private UserModel $author;

    private UserModel $reader;

    private Discussion $discussion;

    private Post $post;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useUtcStorage();
        $this->access = new FakeForumAccess;
        $this->app->instance(ForumAccessInterface::class, $this->access);
        $this->notices = new RecordingForumNotices;
        $this->app->instance(ForumNoticesInterface::class, $this->notices);

        $this->author = UserModel::factory()->create(['name' => 'Ana', 'display_name' => null]);
        $this->reader = UserModel::factory()->create(['name' => 'Luis', 'display_name' => null]);
        $this->access->letRead($this->author, $this->reader);
        [$this->discussion, $this->post] = $this->aDiscussion($this->aCategory(), authorId: $this->author->id);
    }

    public function test_a_reader_likes_a_post_once_and_the_author_hears_about_it_once(): void
    {
        $likes = $this->service()->like($this->reader, $this->post->id());
        $again = $this->service()->like($this->reader, $this->post->id());

        $this->assertSame(1, $likes->count);
        $this->assertTrue($likes->likedByViewer);
        $this->assertSame([strtolower($this->reader->id)], $likes->latestAccountIds);
        $this->assertSame(1, $again->count);
        $this->assertSame([[$this->post->id()->value, strtolower($this->reader->id)]], $this->notices->liked);
    }

    public function test_another_click_takes_the_like_back(): void
    {
        $this->service()->like($this->reader, $this->post->id());

        $likes = $this->service()->unlike($this->reader, $this->post->id());

        $this->assertSame(0, $likes->count);
        $this->assertFalse($likes->likedByViewer);
        // Taking back what was not there does nothing
        $this->assertSame(0, $this->service()->unlike($this->reader, $this->post->id())->count);
    }

    public function test_nobody_likes_their_own_post(): void
    {
        $this->expectException(ForumActionDeniedException::class);

        $this->service()->like($this->author, $this->post->id());
    }

    public function test_nobody_likes_a_post_of_the_system_account(): void
    {
        $systemId = app(SystemAccountInterface::class)->id();
        $post = app(ForumWriter::class)->reply($this->discussion->id(), PostId::generate(), $systemId, self::aBody('Aviso'), CarbonImmutable::now(), needsApproval: false);

        $this->assertFalse($this->service()->canLike($this->reader, $post, $this->discussion));
        $this->expectException(ForumActionDeniedException::class);
        $this->service()->like($this->reader, $post->id());
    }

    public function test_a_post_the_person_may_not_read_does_not_exist_for_them(): void
    {
        $stranger = UserModel::factory()->create();

        $this->expectException(PostNotFoundException::class);

        $this->service()->like($stranger, $this->post->id());
    }

    public function test_a_post_that_is_not_visible_cannot_be_liked(): void
    {
        $deleted = app(ForumWriter::class)->deletePostByAuthor($this->post->id(), CarbonImmutable::now());

        $this->assertFalse($this->service()->canLike($this->reader, $deleted, $this->discussion));
        $this->assertFalse($this->service()->canLike(null, $this->post, $this->discussion));
    }

    public function test_nothing_in_a_hidden_discussion_nor_a_post_awaiting_approval_can_be_liked(): void
    {
        $pending = app(ForumWriter::class)->reply($this->discussion->id(), PostId::generate(), strtolower($this->author->id), self::aBody('Espera'), CarbonImmutable::now(), needsApproval: true);
        $this->assertFalse($this->service()->canLike($this->reader, $pending, $this->discussion));

        $this->discussion->hide();
        app(ForumWriter::class)->saveDiscussion($this->discussion);
        // Even for whoever still reads it (its moderators)
        $this->access->letModerate($this->reader);

        $this->assertFalse($this->service()->canLike($this->reader, $this->post, $this->discussion));
        $this->expectException(ForumActionDeniedException::class);
        $this->service()->like($this->reader, $this->post->id());
    }

    public function test_a_page_gets_each_posts_count_the_viewers_like_and_the_latest_names(): void
    {
        $reply = $this->aReply($this->discussion, '2026-11-10 19:00:00');
        $others = UserModel::factory()->count(PostLikeService::NAMES + 1)->create();
        foreach ($others as $index => $other) {
            $this->access->letRead($other);
            $this->travelTo(CarbonImmutable::parse('2026-11-11 10:00:00')->addMinutes($index));
            $this->service()->like($other, $this->post->id());
        }
        $this->service()->like($this->reader, $this->post->id());

        $likes = $this->service()->forPosts($this->reader, [$this->post, $reply]);

        $this->assertSame(PostLikeService::NAMES + 2, $likes[$this->post->id()->value]->count);
        $this->assertTrue($likes[$this->post->id()->value]->likedByViewer);
        $this->assertCount(PostLikeService::NAMES, $likes[$this->post->id()->value]->latestAccountIds);
        $newest = $others->last();
        $this->assertInstanceOf(UserModel::class, $newest);
        $this->assertSame(strtolower($newest->id), $likes[$this->post->id()->value]->latestAccountIds[1]);
        $this->assertSame(0, $likes[$reply->id()->value]->count);
        $this->assertFalse($this->service()->forPosts(null, [$this->post])[$this->post->id()->value]->likedByViewer);
        $this->assertSame('Luis', $this->service()->namesOf($likes)[strtolower($this->reader->id)]);
    }

    private function service(): PostLikeService
    {
        return app(PostLikeService::class);
    }
}
