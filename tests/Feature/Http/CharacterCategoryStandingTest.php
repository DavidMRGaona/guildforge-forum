<?php

declare(strict_types=1);

namespace Modules\Forum\Tests\Feature\Http;

use App\Application\Publications\DTOs\PublicationDTO;
use App\Application\Publications\DTOs\PublicationRosterDTO;
use App\Application\Publications\Enums\PublicationState;
use App\Application\Publications\Services\PublicationSourceRegistryInterface;
use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Modules\Forum\Application\Publications\CampaignSpaceSync;
use Modules\Forum\Application\Services\CategoryAccessPolicy;
use Modules\Forum\Application\Services\CategoryManager;
use Modules\Forum\Application\Services\DefaultCategoriesInstaller;
use Modules\Forum\Domain\Entities\Category;
use Modules\Forum\Domain\Entities\Character;
use Modules\Forum\Domain\Entities\Discussion;
use Modules\Forum\Domain\Repositories\CategoryRepositoryInterface;
use Modules\Forum\Domain\Repositories\CharacterRepositoryInterface;
use Modules\Forum\Domain\ValueObjects\CategorySubject;
use Modules\Forum\Domain\ValueObjects\PublicationKey;
use Modules\Forum\Tests\Support\BuildsCharacters;
use Modules\Forum\Tests\Support\BuildsForumContent;
use Modules\Forum\Tests\Support\FakeCampaignSource;
use Modules\Forum\Tests\Support\FakePublicationSource;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\Modules\ModuleTestCase;
use Tests\Support\Time\UsesUtcStorage;

/**
 * Where a character may be tied, and what becomes of it (forum#108, forum#109): a campaign's
 * space is offered only to its roster, whoever moderates the whole forum included; a character
 * tied to a space its owner left, or whose campaign or category is gone, stays tied but paused,
 * says so in the profile tab, and comes back on its own when its owner rejoins.
 */
final class CharacterCategoryStandingTest extends ModuleTestCase
{
    use BuildsCharacters;
    use BuildsForumContent;
    use UsesUtcStorage;

    private const string CAMPAIGN = '5b2d9e1a-3c4f-4a6b-8d7e-9f0a1b2c3d4e';

    protected ?string $moduleName = 'forum';

    protected bool $autoEnableModule = true;

    private UserModel $player;

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

        $this->player = UserModel::factory()->create();
        $this->moderator = $this->anAccount(CategoryAccessPolicy::MODERATE_FORUM);
        $this->setRoster([$this->player->id]);

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

    public function test_a_campaigns_space_is_offered_only_to_its_roster(): void
    {
        $this->assertContains($this->space->id()->value, $this->offeredTo($this->player));

        // Whoever moderates the whole forum writes there, but plays nowhere: no characters for it
        $offered = $this->offeredTo($this->moderator);
        $this->assertNotContains($this->space->id()->value, $offered);
        $this->assertNotEmpty($offered);

        $this->actingAs($this->moderator)
            ->postJson('/foro/personajes', ['name' => 'Intrusa', 'category' => $this->space->id()->value])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('category');
    }

    public function test_a_director_is_offered_the_space_too(): void
    {
        $this->setRoster([], [$this->moderator->id]);

        $this->assertContains($this->space->id()->value, $this->offeredTo($this->moderator));
    }

    public function test_leaving_the_campaign_pauses_the_character_and_rejoining_resumes_it(): void
    {
        $character = $this->aCharacterOf($this->player->id, 'Paul', boundTo: $this->space);
        $this->assertSame('active', $this->tabRow($this->player)['binding']);
        $this->speakAs($this->player, $character)->assertCreated();

        $this->setRoster([]);

        $row = $this->tabRow($this->player);
        $this->assertSame('left_campaign', $row['binding']);
        $this->assertSame('La caída de Arrakis', $row['categoryName']);
        $this->assertSame($this->space->id()->value, $row['categoryId']);

        $this->setRoster([$this->player->id]);
        $this->assertSame('active', $this->tabRow($this->player)['binding']);
    }

    public function test_whoever_moderates_the_forum_does_not_speak_as_a_character_of_a_campaign_they_left(): void
    {
        $this->setRoster([$this->player->id, $this->moderator->id]);
        $character = $this->aCharacterOf($this->moderator->id, 'Leto', boundTo: $this->space);
        $this->speakAs($this->moderator, $character)->assertCreated();

        $this->setRoster([$this->player->id]);

        $this->speakAs($this->moderator, $character)
            ->assertUnprocessable()
            ->assertJsonPath('reason', 'character_unavailable');
        // Still writes there as themself
        $this->speakAs($this->moderator, null)->assertCreated();
    }

    public function test_a_campaign_taken_down_leaves_the_character_paused(): void
    {
        $this->aCharacterOf($this->player->id, 'Paul', boundTo: $this->space);

        FakePublicationSource::forget(FakeCampaignSource::TYPE, self::CAMPAIGN);
        app(CampaignSpaceSync::class)->sync(new PublicationKey(FakeCampaignSource::TYPE, self::CAMPAIGN));
        $this->asANewProcess();

        $row = $this->tabRow($this->player);
        $this->assertSame('campaign_gone', $row['binding']);
        $this->assertSame('La caída de Arrakis', $row['categoryName']);
    }

    public function test_a_paused_character_keeps_its_category_when_saved_and_may_leave_it(): void
    {
        $character = $this->aCharacterOf($this->player->id, 'Paul', boundTo: $this->space);
        $this->setRoster([]);

        $this->actingAs($this->player)
            ->postJson("/foro/personajes/{$character->id()->value}", ['name' => 'Paul Atreides', 'category' => $this->space->id()->value])
            ->assertOk();
        $this->assertSame($this->space->id()->value, $this->stored($character)->categoryId()?->value);

        $this->actingAs($this->player)
            ->postJson("/foro/personajes/{$character->id()->value}", ['name' => 'Paul Atreides', 'category' => ''])
            ->assertOk();
        $this->assertNull($this->stored($character)->categoryId());
        $this->assertSame('free', $this->tabRow($this->player)['binding']);
    }

    public function test_deleting_a_category_never_unties_its_characters(): void
    {
        $category = $this->aCategory('cronicas', position: 9);
        $character = $this->aCharacterOf($this->player->id, 'Paul', boundTo: $category);
        $shared = $this->aSharedCharacterIn($this->space);
        $general = $this->aCategory('general-2', position: 10);
        [$elsewhere] = $this->aDiscussion($general, authorId: $this->player->id);

        app(CategoryManager::class)->delete($category->id(), null);

        $this->assertSame($category->id()->value, $this->stored($character)->categoryId()?->value);
        $row = $this->tabRow($this->player);
        $this->assertSame('category_gone', $row['binding']);
        $this->assertNull($row['categoryName']);
        // Never usable anywhere else
        $this->speakAs($this->player, $character, $elsewhere)
            ->assertUnprocessable()
            ->assertJsonPath('reason', 'character_unavailable');
        $this->assertSame($this->space->id()->value, $this->stored($shared)->categoryId()?->value);
    }

    /**
     * @param  list<string>  $players
     * @param  list<string>  $directors
     */
    private function setRoster(array $players, array $directors = []): void
    {
        FakeCampaignSource::$rosters[self::CAMPAIGN] = new PublicationRosterDTO(
            array_map(strtolower(...), $players),
            array_map(strtolower(...), $directors),
        );
        $this->asANewProcess();
    }

    /**
     * Rosters are read once per request: start the next one as a new process would.
     */
    private function asANewProcess(): void
    {
        $this->app->forgetScopedInstances();
        foreach (app(Router::class)->getRoutes()->getRoutes() as $route) {
            $route->controller = null;
        }
    }

    /**
     * @return list<string>
     */
    private function offeredTo(UserModel $user): array
    {
        $categories = $this->actingAs($user)->getJson('/foro/personajes')->assertOk()->json('categories');
        $this->assertIsArray($categories);

        return array_values(array_map(static fn (array $category): string => (string) $category['id'], $categories));
    }

    /**
     * @return array<string, mixed>
     */
    private function tabRow(UserModel $user): array
    {
        $row = $this->actingAs($user)->getJson('/foro/personajes')->assertOk()->json('items.0');
        $this->assertIsArray($row);

        return $row;
    }

    /**
     * @return TestResponse<Response>
     */
    private function speakAs(UserModel $user, ?Character $character, ?Discussion $discussion = null): TestResponse
    {
        $discussion ??= $this->discussion;

        return $this->actingAs($user)->postJson("/foro/temas/{$discussion->id()->value}/mensajes", [
            'document' => ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Hola']]]]],
            'character' => $character?->id()->value,
        ]);
    }

    private function stored(Character $character): Character
    {
        return app(CharacterRepositoryInterface::class)->findOrFail($character->id());
    }
}
