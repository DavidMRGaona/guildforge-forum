<?php

declare(strict_types=1);

namespace Modules\Forum\Tests\Feature\Http;

use App\Application\Authorization\Services\AuthorizationServiceInterface;
use App\Infrastructure\Persistence\Eloquent\Models\RoleModel;
use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use DateTimeImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Modules\Forum\Application\Services\CategoryAccessPolicy;
use Modules\Forum\Application\Services\CharacterRules;
use Modules\Forum\Application\Services\CharacterService;
use Modules\Forum\Application\Services\ForumWriter;
use Modules\Forum\Domain\Entities\Category;
use Modules\Forum\Domain\Entities\Character;
use Modules\Forum\Domain\Repositories\CategoryRepositoryInterface;
use Modules\Forum\Domain\Repositories\CharacterRepositoryInterface;
use Modules\Forum\Domain\ValueObjects\CategoryAudience;
use Modules\Forum\Domain\ValueObjects\CategoryId;
use Modules\Forum\Domain\ValueObjects\CategoryModerators;
use Modules\Forum\Domain\ValueObjects\CategorySlug;
use Modules\Forum\Domain\ValueObjects\CharacterId;
use Modules\Forum\Domain\ValueObjects\PostId;
use Modules\Forum\Infrastructure\Persistence\Eloquent\Models\PostModel;
use Modules\Forum\Tests\Support\BuildsCharacters;
use Modules\Forum\Tests\Support\BuildsForumContent;
use Tests\Support\Modules\ModuleTestCase;
use Tests\Support\Time\UsesUtcStorage;

/**
 * Characters from the profile tab and the editor: their owner creates, changes, retires and
 * deletes them (one with posts is retired instead); moderators of the forum, or of the
 * category a character is tied to, change, retire, bring back and delete any.
 */
final class CharacterEndpointsTest extends ModuleTestCase
{
    use BuildsCharacters;
    use BuildsForumContent;
    use UsesUtcStorage;

    protected ?string $moduleName = 'forum';

    protected bool $autoEnableModule = true;

    private UserModel $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useUtcStorage();
        Storage::fake('images');
        $this->owner = UserModel::factory()->create();
    }

    public function test_an_account_creates_a_character_and_finds_it_in_its_tab(): void
    {
        $category = $this->aCategory('campanas');

        $created = $this->actingAs($this->owner)
            ->post('/foro/personajes', ['name' => '  Arwen  ', 'description' => 'Elfa de Rivendel', 'category' => $category->id()->value], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('character.name', 'Arwen')
            ->assertJsonPath('character.initials', 'A')
            ->assertJsonPath('character.portraitUrl', null)
            ->assertJsonPath('character.isRetired', false);

        $id = $created->json('character.id');
        $this->actingAs($this->owner)->getJson('/foro/personajes')
            ->assertOk()
            ->assertJsonPath('items.0.id', $id)
            ->assertJsonPath('items.0.categoryId', $category->id()->value)
            ->assertJsonPath('items.0.categoryName', 'Campanas')
            ->assertJsonPath('items.0.hasPosts', false)
            ->assertJsonPath('createUrl', route('forum.characters.store'))
            ->assertJsonPath('limits.nameMax', Character::MAX_NAME_LENGTH);
    }

    public function test_a_portrait_is_stored_in_the_characters_folder_and_can_be_dropped(): void
    {
        $id = $this->actingAs($this->owner)
            ->post('/foro/personajes', ['name' => 'Arwen', 'portrait' => UploadedFile::fake()->image('arwen.png', 40, 40)], ['Accept' => 'application/json'])
            ->assertCreated()
            ->json('character.id');

        $stored = app(CharacterRepositoryInterface::class)->find(CharacterId::fromString($id));
        $path = $stored?->portraitPath();
        $this->assertIsString($path);
        $this->assertStringStartsWith(Character::PORTRAIT_FOLDER, $path);
        Storage::disk('images')->assertExists($path);

        $this->actingAs($this->owner)
            ->post("/foro/personajes/{$id}", ['name' => 'Arwen', 'remove_portrait' => '1'], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('character.portraitUrl', null);
    }

    public function test_the_form_is_checked_on_the_server(): void
    {
        $this->actingAs($this->owner)
            ->postJson('/foro/personajes', ['name' => str_repeat('a', 61)])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('name');

        $this->actingAs($this->owner)
            ->post('/foro/personajes', ['name' => 'Arwen', 'portrait' => UploadedFile::fake()->create('arwen.svg', 2, 'image/svg+xml')], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('portrait');

        // A category the account may not write in
        $private = $this->aCategoryFor(CategoryAudience::associationMembers(), 'privada');
        $this->actingAs($this->owner)
            ->postJson('/foro/personajes', ['name' => 'Arwen', 'category' => $private->id()->value])
            ->assertUnprocessable()
            ->assertJsonPath('reason', 'category');

        $this->assertSame(0, app(CharacterRepositoryInterface::class)->countOwnedBy($this->owner->id));
    }

    public function test_one_account_has_a_limited_number_of_characters(): void
    {
        for ($index = 0; $index < CharacterService::MAX_PER_ACCOUNT; $index++) {
            $this->aCharacterOf($this->owner->id, "Personaje {$index}");
        }

        $this->actingAs($this->owner)
            ->postJson('/foro/personajes', ['name' => 'Uno más'])
            ->assertUnprocessable()
            ->assertJsonPath('reason', 'too_many');
    }

    public function test_the_setting_decides_who_may_have_characters(): void
    {
        config(['modules.settings.forum.characters_access' => 'nobody']);
        $this->actingAs($this->owner)->postJson('/foro/personajes', ['name' => 'Arwen'])->assertForbidden();
        $this->actingAs($this->owner)->getJson('/foro/personajes')->assertOk()->assertJsonPath('createUrl', null);

        config(['modules.settings.forum.characters_access' => 'permission']);
        $this->actingAs($this->owner)->postJson('/foro/personajes', ['name' => 'Arwen'])->assertForbidden();
        $this->actingAs($this->anAccount(CharacterRules::USE_PERMISSION))->postJson('/foro/personajes', ['name' => 'Arwen'])->assertCreated();

        // Anything else in the settings turns them off
        config(['modules.settings.forum.characters_access' => 'todos']);
        $this->actingAs($this->anAccount(CharacterRules::USE_PERMISSION))->postJson('/foro/personajes', ['name' => 'Arwen'])->assertForbidden();
    }

    public function test_guests_and_other_accounts_never_reach_a_character(): void
    {
        $character = $this->aCharacterOf($this->owner->id);
        $id = $character->id()->value;

        $this->postJson("/foro/personajes/{$id}/retirar")->assertUnauthorized();

        $stranger = UserModel::factory()->create();
        $this->actingAs($stranger)->postJson("/foro/personajes/{$id}", ['name' => 'Robado'])->assertNotFound();
        $this->actingAs($stranger)->postJson("/foro/personajes/{$id}/retirar")->assertNotFound();
        $this->actingAs($stranger)->deleteJson("/foro/personajes/{$id}")->assertNotFound();
        $this->actingAs($stranger)->getJson('/foro/personajes')->assertOk()->assertJsonCount(0, 'items');

        $this->assertSame('Arwen', app(CharacterRepositoryInterface::class)->find($character->id())?->name());
    }

    public function test_a_character_without_posts_is_deleted_and_one_with_posts_is_retired_instead(): void
    {
        $unused = $this->aCharacterOf($this->owner->id, 'Sin mensajes');
        $this->actingAs($this->owner)->deleteJson("/foro/personajes/{$unused->id()->value}")->assertNoContent();
        $this->assertNull(app(CharacterRepositoryInterface::class)->find($unused->id()));

        $used = $this->aCharacterWithAPost();
        $this->actingAs($this->owner)->deleteJson("/foro/personajes/{$used->id()->value}")
            ->assertConflict()
            ->assertJsonPath('reason', 'has_posts');

        $this->actingAs($this->owner)->postJson("/foro/personajes/{$used->id()->value}/retirar")
            ->assertOk()
            ->assertJsonPath('character.isRetired', true);
        $this->actingAs($this->owner)->getJson('/foro/personajes')
            ->assertJsonPath('items.0.hasPosts', true)
            ->assertJsonPath('items.0.isRetired', true);

        $this->actingAs($this->owner)->postJson("/foro/personajes/{$used->id()->value}/recuperar")
            ->assertOk()
            ->assertJsonPath('character.isRetired', false);
    }

    public function test_moderation_retires_for_good_and_deletes_what_has_posts(): void
    {
        $moderator = $this->anAccount(CategoryAccessPolicy::MODERATE_FORUM);
        $used = $this->aCharacterWithAPost();
        $id = $used->id()->value;

        $this->actingAs($moderator)->postJson("/foro/personajes/{$id}", ['name' => 'Nombre cambiado'])
            ->assertOk()
            ->assertJsonPath('character.name', 'Nombre cambiado');
        $this->actingAs($moderator)->postJson("/foro/personajes/{$id}/retirar")->assertOk();

        $this->actingAs($this->owner)->postJson("/foro/personajes/{$id}/recuperar")
            ->assertConflict()
            ->assertJsonPath('reason', 'locked');
        $this->actingAs($this->owner)->getJson('/foro/personajes')
            ->assertJsonPath('items.0.isRetirementLocked', true)
            ->assertJsonPath('items.0.actions.restoreUrl', null);

        $this->actingAs($moderator)->deleteJson("/foro/personajes/{$id}")->assertNoContent();
        $this->assertNull(app(CharacterRepositoryInterface::class)->find($used->id()));
        // Its posts show their account from then on
        $this->assertSame(0, PostModel::query()->whereNotNull('character_id')->count());
    }

    public function test_whoever_moderates_a_category_moderates_the_characters_tied_to_it_only(): void
    {
        $role = RoleModel::query()->create(['name' => 'directores', 'display_name' => 'Dirección']);
        $director = UserModel::factory()->create();
        $director->roles()->attach($role->id);
        app(AuthorizationServiceInterface::class)->invalidateCacheFor($director);

        $space = new Category(CategoryId::generate(), 'Espacio', new CategorySlug('espacio'), moderators: new CategoryModerators([$role->id]));
        app(CategoryRepositoryInterface::class)->save($space);

        $tied = $this->aCharacterOf($this->owner->id, 'Del espacio', boundTo: $space);
        $free = $this->aCharacterOf($this->owner->id, 'Libre');

        $this->actingAs($director)->postJson("/foro/personajes/{$tied->id()->value}/retirar")->assertOk();
        $this->actingAs($director)->postJson("/foro/personajes/{$free->id()->value}/retirar")->assertNotFound();
    }

    public function test_a_moderator_never_moves_a_character_to_another_category(): void
    {
        $moderator = $this->anAccount(CategoryAccessPolicy::MODERATE_FORUM);
        $category = $this->aCategory('campanas');
        $character = $this->aCharacterOf($this->owner->id);

        $this->actingAs($moderator)->postJson("/foro/personajes/{$character->id()->value}", ['name' => 'Arwen', 'category' => $category->id()->value])->assertOk();

        $this->assertNull(app(CharacterRepositoryInterface::class)->find($character->id())?->categoryId());
    }

    private function aCharacterWithAPost(): Character
    {
        $character = $this->aCharacterOf($this->owner->id);
        [$discussion] = $this->aDiscussion($this->aCategory(), authorId: $this->owner->id);
        app(ForumWriter::class)->reply($discussion->id(), PostId::generate(), $this->owner->id, self::aBody('En personaje'), new DateTimeImmutable('2026-11-12 11:00:00'), false, $character->id());

        return $character;
    }
}
