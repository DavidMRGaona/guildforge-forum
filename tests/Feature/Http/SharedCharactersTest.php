<?php

declare(strict_types=1);

namespace Modules\Forum\Tests\Feature\Http;

use App\Application\Publications\DTOs\PublicationDTO;
use App\Application\Publications\DTOs\PublicationRosterDTO;
use App\Application\Publications\Enums\PublicationState;
use App\Application\Publications\Services\PublicationSourceRegistryInterface;
use App\Application\Services\UserServiceInterface;
use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use Modules\Forum\Application\Publications\CampaignSpaceSync;
use Modules\Forum\Application\Services\CategoryAccessPolicy;
use Modules\Forum\Application\Services\DefaultCategoriesInstaller;
use Modules\Forum\Domain\Entities\Category;
use Modules\Forum\Domain\Entities\Character;
use Modules\Forum\Domain\Entities\Discussion;
use Modules\Forum\Domain\Repositories\CategoryRepositoryInterface;
use Modules\Forum\Domain\Repositories\CharacterRepositoryInterface;
use Modules\Forum\Domain\ValueObjects\CategorySubject;
use Modules\Forum\Domain\ValueObjects\CharacterId;
use Modules\Forum\Domain\ValueObjects\PublicationKey;
use Modules\Forum\Infrastructure\Persistence\Eloquent\Models\PostModel;
use Modules\Forum\Tests\Support\BuildsCharacters;
use Modules\Forum\Tests\Support\BuildsForumContent;
use Modules\Forum\Tests\Support\FakeCampaignSource;
use Modules\Forum\Tests\Support\FakePublicationSource;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\Modules\ModuleTestCase;
use Tests\Support\Time\UsesUtcStorage;

/**
 * Characters shared by the directors of a campaign (PNJ): they belong to the campaign's space,
 * its directors manage and speak as them, and a post that speaks as one hides the account that
 * wrote it from everyone but the space's moderators (and the author).
 */
final class SharedCharactersTest extends ModuleTestCase
{
    use BuildsCharacters;
    use BuildsForumContent;
    use UsesUtcStorage;

    private const string CAMPAIGN = '7d3c1e8a-2b4f-4c6d-9e0a-1f2b3c4d5e6f';

    protected ?string $moduleName = 'forum';

    protected bool $autoEnableModule = true;

    private UserModel $director;

    private UserModel $coDirector;

    private UserModel $player;

    private UserModel $outsider;

    private UserModel $moderator;

    private Category $space;

    private Discussion $discussion;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useUtcStorage();
        Storage::fake('images');
        FakePublicationSource::reset();
        FakeCampaignSource::resetRosters();
        app(PublicationSourceRegistryInterface::class)->register(FakeCampaignSource::class, 'example');
        app(DefaultCategoriesInstaller::class)->install();

        $this->director = UserModel::factory()->create(['name' => 'Dirección Uno', 'display_name' => 'Dirección Uno']);
        $this->coDirector = UserModel::factory()->create(['name' => 'Dirección Dos', 'display_name' => 'Dirección Dos']);
        $this->player = UserModel::factory()->create(['name' => 'Jugadora', 'display_name' => 'Jugadora']);
        $this->outsider = UserModel::factory()->create(['name' => 'Ajena']);
        $this->moderator = $this->anAccount(CategoryAccessPolicy::MODERATE_FORUM);
        FakeCampaignSource::$rosters[self::CAMPAIGN] = new PublicationRosterDTO([$this->player->id], [$this->director->id, $this->coDirector->id]);

        FakePublicationSource::put(new PublicationDTO(FakeCampaignSource::TYPE, self::CAMPAIGN, 'La caída de Arrakis', 'Campaña de Dune.', 'https://example.test/c', PublicationState::Active));
        app(CampaignSpaceSync::class)->sync(new PublicationKey(FakeCampaignSource::TYPE, self::CAMPAIGN));
        $space = app(CategoryRepositoryInterface::class)->findBySubject(new CategorySubject(FakeCampaignSource::TYPE, self::CAMPAIGN));
        $this->assertInstanceOf(Category::class, $space);
        $this->space = $space;
        [$this->discussion] = $this->aDiscussion($this->space, authorId: $this->player->id);
    }

    protected function tearDown(): void
    {
        FakePublicationSource::reset();
        FakeCampaignSource::resetRosters();

        parent::tearDown();
    }

    // --- Managing ---

    public function test_a_guest_manages_nothing(): void
    {
        $this->getJson($this->sharedUrl())->assertUnauthorized();
        $this->postJson($this->sharedUrl(), ['name' => 'Nuevo'])->assertUnauthorized();
    }

    public function test_the_directors_create_and_list_the_shared_characters_of_their_space(): void
    {
        $created = $this->actingAs($this->director)
            ->post($this->sharedUrl(), ['name' => 'Mercader', 'description' => 'Vende de todo', 'portrait' => UploadedFile::fake()->image('m.png', 40, 40)], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('character.name', 'Mercader')
            ->assertJsonPath('character.isShared', true)
            ->assertJsonPath('character.hasPosts', false);

        $stored = app(CharacterRepositoryInterface::class)->find(CharacterId::fromString((string) $created->json('character.id')));
        $this->assertInstanceOf(Character::class, $stored);
        $this->assertNull($stored->ownerId());
        $this->assertSame($this->space->id()->value, $stored->categoryId()?->value);
        $this->assertStringStartsWith(Character::PORTRAIT_FOLDER, (string) $stored->portraitPath());

        // The other director sees and changes it: it is the campaign's, not an account's
        $this->actingAs($this->coDirector)->getJson($this->sharedUrl())
            ->assertOk()
            ->assertJsonCount(1, 'items')
            ->assertJsonPath('items.0.name', 'Mercader')
            ->assertJsonPath('createUrl', route('forum.characters.shared.store', ['category' => $this->space->slug()->value]));
        $this->actingAs($this->coDirector)
            ->post("/foro/personajes/{$stored->id()->value}", ['name' => 'Mercader gruñón'], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('character.name', 'Mercader gruñón');
        $this->actingAs($this->coDirector)->postJson("/foro/personajes/{$stored->id()->value}/retirar")->assertOk()->assertJsonPath('character.isRetired', true);
        $this->actingAs($this->director)->postJson("/foro/personajes/{$stored->id()->value}/recuperar")->assertOk()->assertJsonPath('character.isRetired', false);
        $this->actingAs($this->director)->deleteJson("/foro/personajes/{$stored->id()->value}")->assertNoContent();
        $this->assertNull(app(CharacterRepositoryInterface::class)->find($stored->id()));
    }

    public function test_a_player_who_does_not_direct_and_a_stranger_manage_nothing(): void
    {
        $shared = $this->aSharedCharacterIn($this->space);

        $this->actingAs($this->player)->getJson($this->sharedUrl())->assertForbidden();
        $this->actingAs($this->player)->postJson($this->sharedUrl(), ['name' => 'Nuevo'])->assertForbidden();
        $this->actingAs($this->outsider)->getJson($this->sharedUrl())->assertNotFound();
        $this->actingAs($this->outsider)->postJson($this->sharedUrl(), ['name' => 'Nuevo'])->assertNotFound();

        foreach ([$this->player, $this->outsider] as $person) {
            $this->actingAs($person)->postJson("/foro/personajes/{$shared->id()->value}", ['name' => 'Mío'])->assertNotFound();
            $this->actingAs($person)->postJson("/foro/personajes/{$shared->id()->value}/retirar")->assertNotFound();
            $this->actingAs($person)->deleteJson("/foro/personajes/{$shared->id()->value}")->assertNotFound();
        }
        $this->assertSame('Mercader', app(CharacterRepositoryInterface::class)->findOrFail($shared->id())->name());
        $this->assertSame(1, app(CharacterRepositoryInterface::class)->countSharedIn($this->space->id()));
    }

    public function test_moderation_sees_and_retires_them_but_does_not_create_them(): void
    {
        $shared = $this->aSharedCharacterIn($this->space);

        $this->actingAs($this->moderator)->getJson($this->sharedUrl())->assertOk()->assertJsonCount(1, 'items')->assertJsonPath('createUrl', null);
        $this->actingAs($this->moderator)->postJson($this->sharedUrl(), ['name' => 'Nuevo'])->assertForbidden();

        // What moderation retires only moderation brings back
        $this->actingAs($this->moderator)->postJson("/foro/personajes/{$shared->id()->value}/retirar")->assertOk();
        $this->actingAs($this->director)->postJson("/foro/personajes/{$shared->id()->value}/recuperar")->assertStatus(409)->assertJsonPath('reason', 'locked');
        $this->actingAs($this->moderator)->postJson("/foro/personajes/{$shared->id()->value}/recuperar")->assertOk();
    }

    public function test_a_space_holds_a_limited_number_of_them_and_only_one_that_belongs_to_a_campaign(): void
    {
        for ($i = 1; $i <= 30; $i++) {
            $this->aSharedCharacterIn($this->space, "PNJ {$i}");
        }

        $this->actingAs($this->director)->postJson($this->sharedUrl(), ['name' => 'Uno más'])->assertUnprocessable()->assertJsonPath('reason', 'too_many');

        $general = $this->aCategory('foro-general');
        $this->actingAs($this->director)->getJson("/foro/c/{$general->slug()->value}/pnj")->assertNotFound();
    }

    public function test_the_space_page_offers_the_manager_only_to_the_directors_and_moderation(): void
    {
        $url = '/foro/c/'.$this->space->slug()->value;

        foreach ([$this->director, $this->coDirector, $this->moderator] as $person) {
            $this->actingAs($person)->get($url)
                ->assertOk()
                ->assertInertia(fn (AssertableInertia $page) => $page->where('sharedCharactersUrl', route('forum.characters.shared.index', ['category' => $this->space->slug()->value]))->etc());
        }
        $this->actingAs($this->player)->get($url)
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('sharedCharactersUrl', null)->etc());
    }

    public function test_a_roster_that_cannot_be_read_gives_the_directors_nothing(): void
    {
        $shared = $this->aSharedCharacterIn($this->space);
        FakeCampaignSource::$rosterFails = true;
        $this->asANewProcess();

        $this->actingAs($this->director)->getJson($this->sharedUrl())->assertNotFound();
        $this->reply($this->director, $shared)->assertNotFound();
        // Only the forum's moderators still see the space
        $this->actingAs($this->moderator)->getJson($this->sharedUrl())->assertOk();
    }

    public function test_characters_off_means_no_new_shared_characters(): void
    {
        config(['modules.settings.forum.characters_access' => 'nobody']);

        $this->actingAs($this->director)->postJson($this->sharedUrl(), ['name' => 'Mercader'])->assertForbidden();
    }

    public function test_a_shared_character_with_posts_is_retired_never_deleted(): void
    {
        $shared = $this->aSharedCharacterIn($this->space);
        $this->reply($this->director, $shared)->assertCreated();

        $this->actingAs($this->director)->deleteJson("/foro/personajes/{$shared->id()->value}")->assertStatus(409)->assertJsonPath('reason', 'has_posts');
        // Moderation neither: its posts would show who wrote them
        $this->actingAs($this->moderator)->deleteJson("/foro/personajes/{$shared->id()->value}")->assertStatus(409)->assertJsonPath('reason', 'has_posts');
        $this->actingAs($this->director)->postJson("/foro/personajes/{$shared->id()->value}/retirar")->assertOk();

        $this->assertNotNull(app(CharacterRepositoryInterface::class)->find($shared->id()));
        $this->actingAs($this->player)->get($this->discussionUrl())
            ->assertInertia(fn (AssertableInertia $page) => $page->where('posts.1.character.name', 'Mercader')->where('posts.1.character.isRetired', true)->etc());
    }

    // --- Writing and the editor ---

    public function test_the_directors_editor_offers_the_shared_characters_next_to_their_own(): void
    {
        $own = $this->aCharacterOf($this->director->id, 'Paul');
        $shared = $this->aSharedCharacterIn($this->space, 'Mercader');
        $this->aSharedCharacterIn($this->space, 'Retirado', retired: true);
        $this->aSharedCharacterIn($this->aCategory('otro'), 'De otro espacio');

        $this->actingAs($this->director)->get($this->discussionUrl())
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('reply.characters.items', 2)
                ->where('reply.characters.items', fn ($items): bool => array_column($items->all(), 'id') === [$shared->id()->value, $own->id()->value] || array_column($items->all(), 'id') === [$own->id()->value, $shared->id()->value])
                ->etc());

        foreach ([$this->player, $this->moderator] as $person) {
            $this->actingAs($person)->get($this->discussionUrl())
                ->assertOk()
                ->assertInertia(fn (AssertableInertia $page) => $page->where('reply.characters.items', fn ($items): bool => $items->all() === [])->etc());
        }
    }

    public function test_only_the_directors_speak_as_them_and_only_in_their_space(): void
    {
        $shared = $this->aSharedCharacterIn($this->space);
        $elsewhere = $this->aCategory('foro-general');
        [$otherDiscussion] = $this->aDiscussion($elsewhere, authorId: $this->director->id);

        $this->reply($this->player, $shared)->assertUnprocessable()->assertJsonPath('reason', 'character_unavailable');
        $this->reply($this->moderator, $shared)->assertUnprocessable()->assertJsonPath('reason', 'character_unavailable');
        $this->reply($this->director, $shared, $otherDiscussion)->assertUnprocessable()->assertJsonPath('reason', 'character_unavailable');
        $this->reply($this->director, $shared)->assertCreated();
        $this->reply($this->coDirector, $shared)->assertCreated();
        $this->assertSame(2, PostModel::query()->where('character_id', $shared->id()->value)->count());
    }

    public function test_a_retired_shared_character_is_not_written_as(): void
    {
        $shared = $this->aSharedCharacterIn($this->space, retired: true);

        $this->reply($this->director, $shared)->assertUnprocessable();
    }

    public function test_a_shared_character_never_opens_a_discussion(): void
    {
        $shared = $this->aSharedCharacterIn($this->space);

        $this->actingAs($this->director)->postJson('/foro/temas', [
            'category' => $this->space->id()->value,
            'title' => 'Aviso del mercader',
            'document' => $this->doc('Hola'),
            'character' => $shared->id()->value,
        ])->assertUnprocessable()->assertJsonPath('reason', 'character_unavailable');

        // Nor does an edit turn a first post into its voice
        $first = (string) PostModel::query()->where('discussion_id', $this->discussion->id()->value)->value('id');
        $this->actingAs($this->player)->patchJson("/foro/mensajes/{$first}", ['document' => $this->doc('Editado'), 'character' => $shared->id()->value])->assertUnprocessable();
    }

    // --- Who sees who wrote it ---

    public function test_the_page_shows_the_shared_character_and_names_its_author_only_to_those_who_may_know(): void
    {
        $shared = $this->aSharedCharacterIn($this->space);
        $this->reply($this->director, $shared)->assertCreated();

        // Readers: the player, and the other director (who did not write it)
        $this->actingAs($this->player)->get($this->discussionUrl())
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('posts.1.character.name', 'Mercader')
                ->where('posts.1.character.isShared', true)
                ->where('posts.1.authorId', null)
                ->where('posts.1.authorName', null)
                ->where('posts.1.isStarter', false)
                ->where('posts.0.authorName', 'Jugadora')
                ->etc());

        // Whoever directs the campaign or moderates, and the author, see who wrote it
        foreach ([$this->director, $this->coDirector, $this->moderator] as $person) {
            $this->actingAs($person)->get($this->discussionUrl())
                ->assertOk()
                ->assertInertia(fn (AssertableInertia $page) => $page
                    ->where('posts.1.character.name', 'Mercader')
                    ->where('posts.1.authorId', $this->director->id)
                    ->where('posts.1.authorName', 'Dirección Uno')
                    ->etc());
        }
    }

    public function test_the_author_of_a_discussion_is_not_given_away_by_a_shared_character_that_answers_as_them(): void
    {
        $shared = $this->aSharedCharacterIn($this->space);
        // The player opened the discussion, and a director speaks as the PNJ: nobody is the starter
        $this->reply($this->director, $shared)->assertCreated();

        $this->actingAs($this->player)->get($this->discussionUrl())
            ->assertInertia(fn (AssertableInertia $page) => $page->where('posts.0.isStarter', true)->where('posts.1.isStarter', false)->etc());
    }

    public function test_the_lists_name_the_shared_character_not_the_account_behind_it(): void
    {
        $shared = $this->aSharedCharacterIn($this->space);
        $this->reply($this->director, $shared)->assertCreated();

        $this->actingAs($this->player)->get('/foro/c/'.$this->space->slug()->value)
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('discussions.items.0.lastPost.authorName', 'Mercader')->etc());
        $this->actingAs($this->player)->get('/foro')
            ->assertOk()
            ->assertSee('Mercader', false)
            ->assertDontSee('Dirección Uno', false);
    }

    public function test_a_quote_of_a_shared_character_names_it(): void
    {
        $shared = $this->aSharedCharacterIn($this->space);
        $postId = (string) $this->reply($this->director, $shared)->assertCreated()->json('post.id');

        $html = (string) $this->actingAs($this->player)->postJson("/foro/temas/{$this->discussion->id()->value}/mensajes", [
            'document' => ['type' => 'doc', 'content' => [['type' => 'postQuote', 'attrs' => ['postId' => $postId], 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Habla']]]]]]],
        ])->assertCreated()->json('post.html');

        $this->assertStringContainsString('Mercader', $html);
        $this->assertStringNotContainsString('Dirección Uno', $html);
    }

    // --- Leaving the direction, anonymizing, missing source ---

    public function test_who_stops_directing_cannot_use_them_but_their_messages_stay(): void
    {
        $shared = $this->aSharedCharacterIn($this->space);
        $postId = (string) $this->reply($this->director, $shared)->assertCreated()->json('post.id');

        FakeCampaignSource::$rosters[self::CAMPAIGN] = new PublicationRosterDTO([$this->player->id, $this->director->id], [$this->coDirector->id]);
        $this->asANewProcess();

        $this->reply($this->director, $shared)->assertUnprocessable()->assertJsonPath('reason', 'character_unavailable');
        $this->actingAs($this->director)->getJson($this->sharedUrl())->assertForbidden();
        $this->actingAs($this->director)->postJson($this->sharedUrl(), ['name' => 'Nuevo'])->assertForbidden();
        $this->actingAs($this->director)->postJson("/foro/personajes/{$shared->id()->value}/retirar")->assertNotFound();
        $this->actingAs($this->director)->get($this->discussionUrl())
            ->assertInertia(fn (AssertableInertia $page) => $page->where('reply.characters.items', fn ($items): bool => $items->all() === [])->etc());

        // The message keeps the character; correcting its text keeps it too
        $this->actingAs($this->director)->patchJson("/foro/mensajes/{$postId}", ['document' => $this->doc('Corregido'), 'character' => $shared->id()->value])->assertOk();
        $this->assertSame($shared->id()->value, PostModel::query()->whereKey($postId)->value('character_id'));
        $this->actingAs($this->player)->get($this->discussionUrl())
            ->assertInertia(fn (AssertableInertia $page) => $page->where('posts.1.character.name', 'Mercader')->where('posts.1.authorName', null)->etc());
    }

    public function test_without_the_campaigns_source_nothing_of_this_shows_and_it_comes_back_with_it(): void
    {
        $shared = $this->aSharedCharacterIn($this->space);
        $this->reply($this->director, $shared)->assertCreated();
        $registry = app(PublicationSourceRegistryInterface::class);

        $registry->unregisterModule('example');
        $this->asANewProcess();

        $this->actingAs($this->director)->getJson($this->sharedUrl())->assertNotFound();
        $this->actingAs($this->director)->postJson($this->sharedUrl(), ['name' => 'Nuevo'])->assertNotFound();
        $this->actingAs($this->director)->get($this->discussionUrl())->assertNotFound();
        $this->reply($this->director, $shared)->assertNotFound();
        $this->assertNotNull(app(CharacterRepositoryInterface::class)->find($shared->id()), 'Hiding deletes nothing.');

        $registry->register(FakeCampaignSource::class, 'example');
        $this->asANewProcess();

        $this->actingAs($this->director)->getJson($this->sharedUrl())->assertOk()->assertJsonCount(1, 'items');
        $this->reply($this->director, $shared)->assertCreated();
    }

    public function test_when_the_writer_is_anonymized_the_post_keeps_the_character_and_moderation_sees_the_anonymous_name(): void
    {
        $shared = $this->aSharedCharacterIn($this->space);
        $this->reply($this->director, $shared)->assertCreated();

        app(UserServiceInterface::class)->anonymize($this->director->id);
        $anonymous = UserModel::query()->findOrFail($this->director->id)->display_name ?? UserModel::query()->findOrFail($this->director->id)->name;
        $this->assertNotSame('Dirección Uno', $anonymous);

        $this->actingAs($this->moderator)->get($this->discussionUrl())
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('posts.1.character.name', 'Mercader')
                ->where('posts.1.authorName', fn ($name): bool => $name !== 'Dirección Uno' && $name !== null)
                ->etc());
        $this->actingAs($this->player)->get($this->discussionUrl())
            ->assertInertia(fn (AssertableInertia $page) => $page->where('posts.1.character.name', 'Mercader')->where('posts.1.authorName', null)->etc());
        $this->assertNotNull(app(CharacterRepositoryInterface::class)->find($shared->id()));
    }

    // --- Helpers ---

    private function sharedUrl(): string
    {
        return '/foro/c/'.$this->space->slug()->value.'/pnj';
    }

    private function discussionUrl(): string
    {
        $number = (int) $this->discussion->number();

        return "/foro/t/{$number}-".str($this->discussion->title()->value)->slug();
    }

    /**
     * @return array<string, mixed>
     */
    private function doc(string $text): array
    {
        return ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $text]]]]];
    }

    /**
     * @return TestResponse<Response>
     */
    private function reply(UserModel $as, Character $character, ?Discussion $discussion = null): TestResponse
    {
        $discussion ??= $this->discussion;

        return $this->actingAs($as)->postJson("/foro/temas/{$discussion->id()->value}/mensajes", ['document' => $this->doc('Habla'), 'character' => $character->id()->value]);
    }

    private function asANewProcess(): void
    {
        $this->app->forgetScopedInstances();
        foreach (app(Router::class)->getRoutes()->getRoutes() as $route) {
            $route->controller = null;
        }
    }
}
