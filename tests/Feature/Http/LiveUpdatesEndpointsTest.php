<?php

declare(strict_types=1);

namespace Modules\Forum\Tests\Feature\Http;

use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use DateTimeImmutable;
use Modules\Forum\Application\Contracts\DiscussionReadsInterface;
use Modules\Forum\Application\Contracts\FollowsInterface;
use Modules\Forum\Application\Services\ForumWriter;
use Modules\Forum\Domain\Entities\Category;
use Modules\Forum\Domain\Entities\Discussion;
use Modules\Forum\Domain\Enums\CategoryFollowOrigin;
use Modules\Forum\Domain\Enums\FollowLevel;
use Modules\Forum\Domain\ValueObjects\CategoryAudience;
use Modules\Forum\Tests\Support\BuildsForumContent;
use Tests\Support\Modules\ModuleTestCase;
use Tests\Support\Time\UsesUtcStorage;

/**
 * What the pages ask for while they are open: the posts after the last one a reader has, and
 * what changed in the forum (unread followed discussions, newer last posts). Both answer
 * without a shared cache, and what a person may not read answers like what does not exist.
 */
final class LiveUpdatesEndpointsTest extends ModuleTestCase
{
    use BuildsForumContent;
    use UsesUtcStorage;

    protected ?string $moduleName = 'forum';

    protected bool $autoEnableModule = true;

    private Category $public;

    private Category $members;

    private UserModel $reader;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useUtcStorage();
        $this->public = $this->aCategoryFor(CategoryAudience::everyone(), 'general');
        $this->members = $this->aCategoryFor(CategoryAudience::associationMembers(), 'socios', position: 1);
        $this->reader = UserModel::factory()->create();
    }

    public function test_nothing_after_the_last_post_answers_no_content(): void
    {
        [$discussion] = $this->aDiscussion($this->public);
        $this->aReply($discussion, '2026-11-10 18:05:00');

        $response = $this->getJson($this->postsUrl($discussion, 2));

        $response->assertNoContent();
        $this->assertNotCached($response->headers->get('Cache-Control'));
    }

    public function test_the_posts_after_the_given_number_come_in_order_with_what_a_page_shows_of_them(): void
    {
        [$discussion] = $this->aDiscussion($this->public);
        $this->aReply($discussion, '2026-11-10 18:05:00', text: 'Segundo');
        $this->aReply($discussion, '2026-11-10 18:06:00', text: 'Tercero');
        $this->aReply($discussion, '2026-11-10 18:07:00', text: 'Cuarto');

        $response = $this->getJson($this->postsUrl($discussion, 2))->assertOk();

        $response->assertJsonPath('highestNumber', 4)
            ->assertJsonPath('more', false)
            ->assertJsonCount(2, 'posts')
            ->assertJsonPath('posts.0.number', 3)
            ->assertJsonPath('posts.1.number', 4)
            ->assertJsonPath('posts.1.url', '/foro/t/'.$discussion->number().'-sesion-del-viernes/4')
            ->assertJsonPath('posts.1.likes.count', 0);
        self::assertStringContainsString('Cuarto', (string) $response->json('posts.1.html'));
        $this->assertNotCached($response->headers->get('Cache-Control'));
    }

    public function test_a_signed_in_reader_gets_the_links_only_they_may_use(): void
    {
        [$discussion] = $this->aDiscussion($this->public);
        $this->aReply($discussion, '2026-11-10 18:05:00');

        $this->actingAs($this->reader)->getJson($this->postsUrl($discussion, 1))
            ->assertOk()
            ->assertJsonPath('posts.0.likes.liked', false)
            ->assertJsonPath('posts.0.canEdit', false)
            ->assertJsonPath('posts.0.moderationUrl', null);
    }

    public function test_the_number_must_be_a_whole_number_from_zero(): void
    {
        [$discussion] = $this->aDiscussion($this->public);
        $url = route('forum.discussions.updates', ['discussion' => $discussion->id()->value]);

        $this->getJson($url)->assertUnprocessable();
        $this->getJson($url.'?despues=abc')->assertUnprocessable();
        $this->getJson($url.'?despues=-1')->assertUnprocessable();
        $this->getJson($url.'?despues=0')->assertOk();
    }

    public function test_a_batch_is_capped_and_says_there_is_more(): void
    {
        [$discussion] = $this->aDiscussion($this->public);
        for ($i = 2; $i <= 60; $i++) {
            $this->aReply($discussion, (new DateTimeImmutable('2026-11-10 19:00:00'))->modify("+{$i} minutes")->format('Y-m-d H:i:s'));
        }

        $this->getJson($this->postsUrl($discussion, 1))
            ->assertOk()
            ->assertJsonCount(50, 'posts')
            ->assertJsonPath('more', true)
            ->assertJsonPath('highestNumber', 60)
            ->assertJsonPath('posts.0.number', 2)
            ->assertJsonPath('posts.49.number', 51);
    }

    public function test_a_discussion_the_person_may_not_read_answers_like_one_that_does_not_exist(): void
    {
        [$private] = $this->aDiscussion($this->members, title: 'Reservado');
        $this->aReply($private, '2026-11-10 18:05:00');

        $missing = $this->getJson('/foro/temas/'.'11111111-1111-4111-8111-111111111111'.'/novedades?despues=1');
        $guest = $this->getJson($this->postsUrl($private, 1));
        $member = $this->actingAs($this->reader)->getJson($this->postsUrl($private, 1));

        $missing->assertNotFound();
        $guest->assertNotFound();
        $member->assertNotFound();
        // The same message (a debug trace names the line of each test request, which is not the answer)
        self::assertSame($missing->json('message'), $guest->json('message'));
        self::assertSame($missing->json('message'), $member->json('message'));
        $this->assertNotCached($guest->headers->get('Cache-Control'));
        self::assertStringNotContainsString('Reservado', (string) $guest->getContent());
    }

    public function test_a_hidden_discussion_is_only_followed_by_whoever_moderates_it(): void
    {
        [$hidden] = $this->aDiscussion($this->public, title: 'Oculto');
        $this->aReply($hidden, '2026-11-10 18:05:00');
        $hidden->hide();
        app(ForumWriter::class)->saveDiscussion($hidden);

        $this->actingAs($this->reader)->getJson($this->postsUrl($hidden, 1))->assertNotFound();
        $this->actingAs($this->anAccount('forum:forum.moderate'))->getJson($this->postsUrl($hidden, 1))->assertOk()->assertJsonPath('posts.0.number', 2);
    }

    public function test_posts_awaiting_approval_are_never_sent_to_anyone(): void
    {
        [$discussion] = $this->aDiscussion($this->public);
        $this->aReply($discussion, '2026-11-10 18:05:00', needsApproval: true, text: 'Pendiente secreto');

        $this->actingAs($this->reader)->getJson($this->postsUrl($discussion, 1))->assertNoContent();
        $this->actingAs($this->anAccount('forum:forum.moderate'))->getJson($this->postsUrl($discussion, 1))->assertNoContent();
    }

    public function test_asking_is_limited(): void
    {
        [$discussion] = $this->aDiscussion($this->public);

        for ($i = 0; $i < 30; $i++) {
            $this->getJson($this->postsUrl($discussion, 1))->assertNoContent();
        }

        $this->getJson($this->postsUrl($discussion, 1))->assertStatus(429);
    }

    public function test_the_forum_summary_needs_a_session(): void
    {
        $this->getJson(route('forum.updates'))->assertUnauthorized();
    }

    public function test_followed_discussions_with_unread_posts_are_counted(): void
    {
        [$followed] = $this->aDiscussion($this->public, title: 'Seguido');
        [$other] = $this->aDiscussion($this->public, title: 'Otro');
        $this->follow($followed);

        $response = $this->actingAs($this->reader)->getJson(route('forum.updates'))->assertOk();
        $response->assertJsonPath('unreadFollowed', 1)->assertJsonPath('discussions', []);
        $this->assertNotCached($response->headers->get('Cache-Control'));

        // Read all of it: nothing is pending
        app(DiscussionReadsInterface::class)->advance($this->reader->id, $followed->id(), 1, new DateTimeImmutable('2026-11-10 19:00:00'));
        $this->actingAs($this->reader)->getJson(route('forum.updates'))->assertJsonPath('unreadFollowed', 0);

        // A new reply makes it count again; one in a discussion not followed never does
        $this->aReply($followed, '2026-11-10 19:10:00');
        $this->aReply($other, '2026-11-10 19:11:00');
        $this->actingAs($this->reader)->getJson(route('forum.updates'))->assertJsonPath('unreadFollowed', 1);
    }

    public function test_discussions_of_followed_categories_are_counted_once(): void
    {
        [$inCategory] = $this->aDiscussion($this->public, '2026-11-10 18:00:00', title: 'En la categoría');
        $this->aDiscussion($this->members, '2026-11-10 18:00:00', title: 'Reservada');
        app(FollowsInterface::class)->followCategory($this->reader->id, $this->public->id(), CategoryFollowOrigin::Person, new DateTimeImmutable('2026-11-01 10:00:00'));
        // Followed on its own as well: still one
        $this->follow($inCategory);

        $this->actingAs($this->reader)->getJson(route('forum.updates'))->assertJsonPath('unreadFollowed', 1);
    }

    public function test_what_the_person_may_no_longer_read_is_not_counted(): void
    {
        [$discussion] = $this->aDiscussion($this->members, title: 'Reservado');
        $this->follow($discussion);

        // The reader holds no association role: the follow stays in the table, the answer has nothing
        $this->actingAs($this->reader)->getJson(route('forum.updates'))->assertJsonPath('unreadFollowed', 0);
    }

    public function test_the_last_post_of_the_discussions_a_list_shows_is_returned_for_the_readable_ones(): void
    {
        [$first] = $this->aDiscussion($this->public, title: 'Primero');
        $this->aReply($first, '2026-11-10 18:05:00');
        [$second] = $this->aDiscussion($this->public, title: 'Segundo');
        [$private] = $this->aDiscussion($this->members, title: 'Reservado');
        [$hidden] = $this->aDiscussion($this->public, title: 'Oculto');
        $hidden->hide();
        app(ForumWriter::class)->saveDiscussion($hidden);
        $unknown = '11111111-1111-4111-8111-111111111111';

        $ids = implode(',', [$first->id()->value, $second->id()->value, $private->id()->value, $hidden->id()->value, $unknown]);
        $response = $this->actingAs($this->reader)->getJson(route('forum.updates', ['temas' => $ids]))->assertOk();

        // Unreadable, hidden and unknown discussions are simply missing: nothing tells them apart
        self::assertEqualsCanonicalizing([$first->id()->value => 2, $second->id()->value => 1], $response->json('discussions'));
    }

    public function test_the_list_of_discussions_must_be_small_and_made_of_ids(): void
    {
        $url = route('forum.updates');

        $this->actingAs($this->reader)->getJson($url.'?temas=not-an-id')->assertUnprocessable();
        $many = implode(',', array_fill(0, 101, '11111111-1111-4111-8111-111111111111'));
        $this->actingAs($this->reader)->getJson($url.'?temas='.$many)->assertUnprocessable();
    }

    public function test_the_summary_is_limited(): void
    {
        for ($i = 0; $i < 20; $i++) {
            $this->actingAs($this->reader)->getJson(route('forum.updates'))->assertOk();
        }

        $this->actingAs($this->reader)->getJson(route('forum.updates'))->assertStatus(429);
    }

    private function postsUrl(Discussion $discussion, int $after): string
    {
        return route('forum.discussions.updates', ['discussion' => $discussion->id()->value, 'despues' => $after]);
    }

    private function follow(Discussion $discussion): void
    {
        app(FollowsInterface::class)->setDiscussionLevel($this->reader->id, $discussion->id(), FollowLevel::Following, new DateTimeImmutable('2026-11-10 18:30:00'));
    }

    private function assertNotCached(?string $header): void
    {
        self::assertStringContainsString('private', (string) $header);
        self::assertStringContainsString('no-store', (string) $header);
    }
}
