<?php

declare(strict_types=1);

namespace Modules\Forum\Tests\Feature\Http;

use App\Application\Profiles\Services\ProfileCardServiceInterface;
use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use App\Infrastructure\Persistence\Eloquent\Models\UserProfileModel;
use DateTimeImmutable;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Forum\Application\Contracts\SystemAccountInterface;
use Modules\Forum\Application\Services\CategoryAccessPolicy;
use Modules\Forum\Application\Services\ForumWriter;
use Modules\Forum\Application\Services\MemberTitleService;
use Modules\Forum\Domain\Entities\Category;
use Modules\Forum\Domain\Entities\Character;
use Modules\Forum\Domain\Entities\Discussion;
use Modules\Forum\Domain\Entities\Post;
use Modules\Forum\Domain\Repositories\PostRepositoryInterface;
use Modules\Forum\Domain\ValueObjects\CategoryAudience;
use Modules\Forum\Domain\ValueObjects\PostId;
use Modules\Forum\ForumServiceProvider;
use Modules\Forum\Tests\Support\BuildsCharacters;
use Modules\Forum\Tests\Support\BuildsForumContent;
use Modules\Forum\Tests\Support\RecordingProfileCards;
use Tests\Support\Modules\ModuleTestCase;
use Tests\Support\Time\UsesUtcStorage;

/**
 * `GET /foro/mensajes/{post}/autor` (forum#134): the card a post's author opens with. It starts from
 * a post the reader may see and only where the page names its writer, joins the core's profile card
 * to what the forum adds, and says nothing about anybody the post does not name.
 */
final class AuthorCardEndpointTest extends ModuleTestCase
{
    use BuildsCharacters;
    use BuildsForumContent;
    use UsesUtcStorage;

    protected ?string $moduleName = 'forum';

    protected bool $autoEnableModule = true;

    private Category $general;

    private Discussion $discussion;

    private UserModel $ana;

    private UserModel $luis;

    private Post $anaReply;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useUtcStorage();
        $this->general = $this->aCategoryFor(CategoryAudience::everyone(), 'general');
        $this->ana = UserModel::factory()->create(['name' => 'ana.login', 'email' => 'ana@example.org', 'display_name' => 'Ana']);
        $this->luis = UserModel::factory()->create(['name' => 'luis.login', 'display_name' => 'Luis']);
        // Ana opens the discussion (1), Luis answers (2), Ana again (3)
        [$this->discussion] = $this->aDiscussion($this->general, authorId: strtolower($this->ana->id));
        $this->postAs($this->luis, 'Hola');
        $this->anaReply = $this->postAs($this->ana, 'Adiós');
    }

    public function test_a_member_gets_the_profile_card_and_what_the_forum_adds(): void
    {
        app(MemberTitleService::class)->change($this->anAccount(CategoryAccessPolicy::MODERATE_FORUM), $this->ana->id, 'Veterana');

        $this->actingAs($this->luis)->getJson($this->cardUrl($this->anaReply))
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('profile.handle', 'ana')
            ->assertJsonPath('profile.publicName', 'Ana')
            ->assertJsonPath('profile.isOwner', false)
            ->assertJsonPath('title', 'Veterana')
            ->assertJsonPath('postCount', 2)
            ->assertJsonPath('topic.count', 2)
            ->assertJsonPath('topic.url', $this->discussionUrl().'?autor='.strtolower($this->ana->id))
            ->assertJsonPath('block.blocked', false)
            ->assertJsonPath('block.blockUrl', '/foro/bloqueos')
            ->assertJsonPath('block.unblockUrl', '/foro/bloqueos/'.strtolower($this->ana->id));
    }

    public function test_it_never_carries_the_account_name_nor_the_email(): void
    {
        $response = $this->actingAs($this->luis)->getJson($this->cardUrl($this->anaReply))->assertOk();

        $this->assertStringNotContainsString('ana.login', (string) $response->getContent());
        $this->assertStringNotContainsString('ana@example.org', (string) $response->getContent());
        $this->assertSame(['profile', 'title', 'postCount', 'topic', 'block'], array_keys((array) $response->json()));
        $this->assertArrayNotHasKey('id', (array) $response->json('profile'));
        $this->assertArrayNotHasKey('email', (array) $response->json('profile'));
    }

    public function test_any_post_of_the_person_opens_the_same_card(): void
    {
        $opening = app(PostRepositoryInterface::class)->withNumbers($this->discussion->id(), [1])[0];

        $this->actingAs($this->luis)->getJson($this->cardUrl($opening))
            ->assertOk()
            ->assertJsonPath('profile.handle', 'ana')
            ->assertJsonPath('topic.count', 2);
    }

    public function test_a_guest_who_may_not_see_the_profile_still_gets_the_forum_part_without_ignoring(): void
    {
        // Profiles are for members by default
        $this->getJson($this->cardUrl($this->anaReply))
            ->assertOk()
            ->assertJsonPath('profile', null)
            ->assertJsonPath('postCount', 2)
            ->assertJsonPath('topic.count', 2)
            ->assertJsonPath('block', null);
    }

    public function test_a_hidden_profile_leaves_only_the_forum_part(): void
    {
        UserProfileModel::query()->whereKey($this->ana->id)->update(['visibility' => 'hidden']);

        $this->actingAs($this->luis)->getJson($this->cardUrl($this->anaReply))
            ->assertOk()
            ->assertJsonPath('profile', null)
            ->assertJsonPath('postCount', 2);
    }

    public function test_the_counts_only_take_in_what_the_reader_may_read(): void
    {
        $members = $this->aCategoryFor(CategoryAudience::signedIn(), 'socios', position: 1);
        $this->aDiscussion($members, authorId: strtolower($this->ana->id), title: 'Solo socios');

        $this->getJson($this->cardUrl($this->anaReply))->assertJsonPath('postCount', 2);
        $this->actingAs($this->luis)->getJson($this->cardUrl($this->anaReply))->assertJsonPath('postCount', 3);
    }

    public function test_a_discussion_only_members_read_counts_for_a_member_and_its_own_topic_count(): void
    {
        $members = $this->aCategoryFor(CategoryAudience::signedIn(), 'socios', position: 1);
        [$private, $first] = $this->aDiscussion($members, authorId: strtolower($this->luis->id), title: 'Solo socios');

        // Only signed-in readers see it, and Luis wrote only that and post 2 of the general one
        $this->actingAs($this->ana)->getJson($this->cardUrl($first))
            ->assertOk()
            ->assertJsonPath('postCount', 2)
            ->assertJsonPath('topic.count', 1)
            ->assertJsonPath('topic.url', '/foro/t/'.(int) $private->number().'-solo-socios?autor='.strtolower($this->luis->id));
    }

    public function test_a_post_the_reader_may_not_read_is_not_found_like_a_missing_one(): void
    {
        $members = $this->aCategoryFor(CategoryAudience::signedIn(), 'socios', position: 1);
        [, $first] = $this->aDiscussion($members, authorId: strtolower($this->ana->id), title: 'Solo socios');

        $missing = $this->getJson('/foro/mensajes/'.PostId::generate()->value.'/autor')->assertNotFound();
        $forbidden = $this->getJson($this->cardUrl($first))->assertNotFound();

        $this->assertSame($missing->getContent(), $forbidden->getContent());
        $this->get('/foro/mensajes/no-es-un-id/autor')->assertNotFound();
    }

    public function test_a_shared_character_never_tells_who_writes_it(): void
    {
        $shared = $this->aSharedCharacterIn($this->general);
        $npcPost = $this->postAs($this->ana, 'Habla el mercader', $shared);
        $cards = $this->recordingCards();

        $this->getJson($this->cardUrl($npcPost))->assertNotFound();
        $this->actingAs($this->luis)->getJson($this->cardUrl($npcPost))->assertNotFound();
        // The core is not even asked about the account behind it
        $this->assertSame([], $cards->asked);

        // Its writer and moderation know who it is
        $this->actingAs($this->ana)->getJson($this->cardUrl($npcPost))->assertOk();
        $this->actingAs($this->anAccount(CategoryAccessPolicy::MODERATE_FORUM))->getJson($this->cardUrl($npcPost))->assertOk();
    }

    public function test_messages_as_a_shared_character_do_not_count_in_the_topic_for_who_may_not_know(): void
    {
        $shared = $this->aSharedCharacterIn($this->general);
        $this->postAs($this->ana, 'Habla el mercader', $shared);

        $this->actingAs($this->luis)->getJson($this->cardUrl($this->anaReply))->assertJsonPath('topic.count', 2)->assertJsonPath('postCount', 2);
        $this->actingAs($this->ana)->getJson($this->cardUrl($this->anaReply))->assertJsonPath('topic.count', 3);
    }

    public function test_a_writer_without_a_public_name_speaking_as_a_character_has_no_card(): void
    {
        $nameless = UserModel::factory()->create(['name' => 'sin.nombre', 'display_name' => null]);
        $character = $this->aCharacterOf(strtolower($nameless->id));
        $post = $this->postAs($nameless, 'En personaje', $character);

        $this->actingAs($this->luis)->getJson($this->cardUrl($post))->assertNotFound();
    }

    public function test_a_post_awaiting_approval_opens_the_card_only_for_its_author_and_moderation(): void
    {
        $pending = app(ForumWriter::class)->reply($this->discussion->id(), PostId::generate(), strtolower($this->luis->id), self::aBody('Pendiente'), new DateTimeImmutable('2026-11-11 09:00:00'), true);

        $this->getJson($this->cardUrl($pending))->assertNotFound();
        $this->actingAs($this->ana)->getJson($this->cardUrl($pending))->assertNotFound();
        $this->actingAs($this->luis)->getJson($this->cardUrl($pending))->assertOk()->assertJsonPath('block', null);
        $this->actingAs($this->anAccount(CategoryAccessPolicy::MODERATE_FORUM))->getJson($this->cardUrl($pending))->assertOk();
    }

    public function test_a_hidden_post_still_names_its_writer_so_it_opens_the_card(): void
    {
        $repository = app(PostRepositoryInterface::class);
        $hidden = $repository->withNumbers($this->discussion->id(), [3])[0];
        $hidden->hide('Spoiler');
        $repository->save($hidden);

        $this->actingAs($this->luis)->getJson($this->cardUrl($hidden))->assertOk()->assertJsonPath('profile.handle', 'ana');
    }

    public function test_the_forums_own_account_has_no_card(): void
    {
        $systemId = app(SystemAccountInterface::class)->id();
        $post = app(ForumWriter::class)->reply($this->discussion->id(), PostId::generate(), strtolower($systemId), self::aBody('Aviso'), new DateTimeImmutable('2026-11-11 09:00:00'), false);

        $this->actingAs($this->luis)->getJson($this->cardUrl($post))->assertNotFound();
        $this->get($this->discussionUrl())
            ->assertInertia(fn (Assert $page) => $page->where('posts.3.authorCardUrl', null)->etc());
    }

    public function test_ignoring_shows_on_the_card_and_never_on_ones_own(): void
    {
        $this->actingAs($this->luis)->postJson('/foro/bloqueos', ['account' => $this->ana->id])->assertSuccessful();

        $this->actingAs($this->luis)->getJson($this->cardUrl($this->anaReply))->assertJsonPath('block.blocked', true);
        $this->actingAs($this->ana)->getJson($this->cardUrl($this->anaReply))
            ->assertOk()
            ->assertJsonPath('block', null)
            ->assertJsonPath('profile.isOwner', true);
    }

    public function test_a_card_the_core_cannot_make_leaves_the_forum_part(): void
    {
        $this->recordingCards();

        $this->actingAs($this->luis)->getJson($this->cardUrl($this->anaReply))
            ->assertOk()
            ->assertJsonPath('profile', null)
            ->assertJsonPath('topic.count', 2);
    }

    public function test_the_discussion_page_offers_the_card_only_where_it_names_the_writer(): void
    {
        $shared = $this->aSharedCharacterIn($this->general);
        $npcPost = $this->postAs($this->ana, 'Habla el mercader', $shared);

        $this->actingAs($this->luis)->get($this->discussionUrl())
            ->assertInertia(fn (Assert $page) => $page
                ->where('posts.0.authorCardUrl', fn (?string $url): bool => $url !== null && str_ends_with($url, '/foro/mensajes/'.$this->firstPostId().'/autor'))
                ->where('posts.2.authorCardUrl', fn (?string $url): bool => $url !== null && str_ends_with($url, $this->cardUrl($this->anaReply)))
                ->where('posts.3.id', $npcPost->id()->value)
                ->where('posts.3.authorCardUrl', null)
                ->etc());
    }

    public function test_it_has_a_limit_of_its_own(): void
    {
        for ($request = 0; $request < ForumServiceProvider::AUTHOR_CARDS_PER_MINUTE; $request++) {
            $this->actingAs($this->luis)->getJson($this->cardUrl($this->anaReply))->assertOk();
        }

        $this->actingAs($this->luis)->getJson($this->cardUrl($this->anaReply))->assertStatus(429);
        // Another person keeps theirs
        $this->actingAs($this->ana)->getJson($this->cardUrl($this->anaReply))->assertOk();
    }

    private function recordingCards(): RecordingProfileCards
    {
        $cards = new RecordingProfileCards;
        $this->app->instance(ProfileCardServiceInterface::class, $cards);

        return $cards;
    }

    private function postAs(UserModel $author, string $text, ?Character $as = null): Post
    {
        return app(ForumWriter::class)->reply(
            $this->discussion->id(),
            PostId::generate(),
            strtolower($author->id),
            self::aBody($text),
            new DateTimeImmutable('2026-11-10 19:00:00'),
            false,
            $as?->id(),
        );
    }

    private function firstPostId(): string
    {
        return app(PostRepositoryInterface::class)->withNumbers($this->discussion->id(), [1])[0]->id()->value;
    }

    private function cardUrl(Post $post): string
    {
        return '/foro/mensajes/'.$post->id()->value.'/autor';
    }

    private function discussionUrl(): string
    {
        return '/foro/t/'.(int) $this->discussion->number().'-'.str($this->discussion->title()->value)->slug();
    }
}
