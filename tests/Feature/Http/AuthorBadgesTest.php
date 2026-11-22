<?php

declare(strict_types=1);

namespace Modules\Forum\Tests\Feature\Http;

use App\Application\Profiles\Services\ProfileBadgeSourceRegistryInterface;
use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use App\Infrastructure\Persistence\Eloquent\Models\UserProfileModel;
use DateTimeImmutable;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Forum\Application\Services\CategoryAccessPolicy;
use Modules\Forum\Application\Services\ForumWriter;
use Modules\Forum\Domain\Entities\Category;
use Modules\Forum\Domain\Entities\Character;
use Modules\Forum\Domain\Entities\Discussion;
use Modules\Forum\Domain\Entities\Post;
use Modules\Forum\Domain\ValueObjects\CategoryAudience;
use Modules\Forum\Domain\ValueObjects\PostId;
use Modules\Forum\Tests\Support\BuildsCharacters;
use Modules\Forum\Tests\Support\BuildsForumContent;
use Tests\Support\Modules\ModuleTestCase;
use Tests\Support\Profiles\FakeProfileBadgeSource;
use Tests\Support\Time\UsesUtcStorage;

/**
 * The featured badges next to a post's author (forum#152): asked from the core once per page with
 * every author shown (ProfileBadgesInterface, which decides on the profile's own rule), and drawn
 * only where the post names a person. A post as a shared character, or as a personal character of
 * someone without a public name, carries none, so badges never tell who is behind a character.
 */
final class AuthorBadgesTest extends ModuleTestCase
{
    use BuildsCharacters;
    use BuildsForumContent;
    use UsesUtcStorage;

    private const string SOURCE = 'tests.forum-badge-source';

    protected ?string $moduleName = 'forum';

    protected bool $autoEnableModule = true;

    private Category $general;

    private Discussion $discussion;

    private UserModel $ana;

    private UserModel $luis;

    private UserModel $reader;

    private FakeProfileBadgeSource $source;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useUtcStorage();
        $this->general = $this->aCategoryFor(CategoryAudience::everyone(), 'general');
        $this->ana = UserModel::factory()->create(['display_name' => 'Ana']);
        $this->luis = UserModel::factory()->create(['display_name' => 'Luis']);
        $this->reader = UserModel::factory()->create(['display_name' => 'Lectora']);
        // Ana opens the discussion (1), Luis answers (2), Ana again (3)
        [$this->discussion] = $this->aDiscussion($this->general, authorId: strtolower($this->ana->id));
        $this->postAs($this->luis, 'Hola');
        $this->postAs($this->ana, 'Adiós');
    }

    public function test_each_post_carries_its_author_badges_from_a_single_call_for_the_whole_page(): void
    {
        $this->registerSource();

        $this->actingAs($this->reader)->get($this->discussionUrl())->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('posts.0.authorBadges.0.key', 'achievements.first')
                ->where('posts.0.authorBadges.0.label', 'Primera partida')
                ->where('posts.0.authorBadges.0.description', 'Jugó su primera partida en la asociación.')
                ->where('posts.0.authorBadges.0.icon', 'trophy')
                ->where('posts.0.authorBadges.0.colour', 'amber')
                ->where('posts.0.authorBadges.0.imageUrl', null)
                ->where('posts.0.authorBadges.0.url', null)
                ->has('posts.1.authorBadges', 1)
                ->has('posts.2.authorBadges', 1)
                ->etc());

        // Asked once, with each author once: never per post
        $this->assertCount(1, $this->source->calls);
        $this->assertEqualsCanonicalizing(
            [strtolower($this->ana->id), strtolower($this->luis->id)],
            $this->source->calls[0],
        );
    }

    public function test_more_posts_ask_no_more(): void
    {
        $this->registerSource();
        foreach (range(1, 6) as $index) {
            $this->postAs($index % 2 === 0 ? $this->ana : UserModel::factory()->create(), 'Mensaje '.$index);
        }

        $this->actingAs($this->reader)->get($this->discussionUrl())->assertOk();

        $this->assertCount(1, $this->source->calls);
    }

    public function test_without_any_badge_source_no_post_carries_badges(): void
    {
        $this->actingAs($this->reader)->get($this->discussionUrl())->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('posts.0.authorBadges', [])
                ->where('posts.1.authorBadges', [])
                ->etc());
    }

    public function test_a_profile_hidden_from_the_reader_shows_no_badges(): void
    {
        $this->registerSource();
        UserProfileModel::query()->whereKey($this->ana->id)->update(['visibility' => 'hidden']);

        $this->actingAs($this->reader)->get($this->discussionUrl())->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('posts.0.authorBadges', [])
                ->has('posts.1.authorBadges', 1)
                ->where('posts.2.authorBadges', [])
                ->etc());
    }

    public function test_a_guest_sees_no_badges_of_profiles_only_members_see(): void
    {
        $this->registerSource();
        UserProfileModel::query()->whereKey($this->luis->id)->update(['visibility' => 'public']);

        $this->get($this->discussionUrl())->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('posts.0.authorBadges', [])
                ->has('posts.1.authorBadges', 1)
                ->etc());
    }

    public function test_in_character_with_a_public_name_the_badges_are_the_writers(): void
    {
        $this->registerSource();
        $this->postAs($this->luis, 'En personaje', $this->aCharacterOf($this->luis->id));

        $this->actingAs($this->reader)->get($this->discussionUrl())->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('posts.3.character.name', 'Arwen')
                ->where('posts.3.authorName', 'Luis')
                ->where('posts.3.authorBadges.0.key', 'achievements.first')
                ->etc());
    }

    public function test_a_writer_without_a_public_name_shows_no_badges_in_character_not_even_to_moderation_or_themselves(): void
    {
        $this->registerSource();
        $player = UserModel::factory()->create(['name' => 'Laura Gómez Ruiz', 'display_name' => null]);
        $this->postAs($player, 'En personaje', $this->aCharacterOf($player->id));
        UserProfileModel::query()->whereKey($player->id)->update(['visibility' => 'public']);

        foreach ([$this->reader, $this->anAccount(CategoryAccessPolicy::MODERATE_FORUM), $player] as $viewer) {
            $this->actingAs($viewer)->get($this->discussionUrl())->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->where('posts.3.authorBadges', [])
                    ->etc());
        }
        foreach ($this->source->calls as $asked) {
            $this->assertNotContains(strtolower($player->id), $asked);
        }
    }

    public function test_a_shared_character_never_carries_the_badges_of_who_writes_it(): void
    {
        $this->registerSource();
        $this->postAs($this->ana, 'Habla el mercader', $this->aSharedCharacterIn($this->general));

        foreach ([$this->reader, $this->anAccount(CategoryAccessPolicy::MODERATE_FORUM), $this->ana] as $viewer) {
            $this->actingAs($viewer)->get($this->discussionUrl())->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->where('posts.3.authorBadges', [])
                    ->has('posts.2.authorBadges', 1)
                    ->etc());
        }
    }

    public function test_the_live_updates_carry_the_badges_too(): void
    {
        $this->registerSource();

        $this->actingAs($this->reader)
            ->getJson('/foro/temas/'.$this->discussion->id()->value.'/novedades?despues=1')
            ->assertOk()
            ->assertJsonPath('posts.0.authorBadges.0.key', 'achievements.first')
            ->assertJsonPath('posts.1.authorBadges.0.key', 'achievements.first');

        $this->assertCount(1, $this->source->calls);
    }

    public function test_the_author_card_carries_the_profile_badges(): void
    {
        $this->registerSource();
        $post = $this->postAs($this->ana, 'Otra vez');

        $this->actingAs($this->reader)->getJson('/foro/mensajes/'.$post->id()->value.'/autor')
            ->assertOk()
            ->assertJsonPath('profile.badges.0.key', 'achievements.first')
            ->assertJsonPath('profile.badges.0.colour', 'amber');
    }

    private function registerSource(): void
    {
        $this->source = new FakeProfileBadgeSource('achievements');
        $this->app->instance(self::SOURCE, $this->source);
        $this->app->make(ProfileBadgeSourceRegistryInterface::class)->register(self::SOURCE, 'achievements');
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

    private function discussionUrl(): string
    {
        return '/foro/t/'.(int) $this->discussion->number().'-'.str($this->discussion->title()->value)->slug();
    }
}
