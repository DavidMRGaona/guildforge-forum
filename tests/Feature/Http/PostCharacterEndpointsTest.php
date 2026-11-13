<?php

declare(strict_types=1);

namespace Modules\Forum\Tests\Feature\Http;

use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use DateTimeImmutable;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use Modules\Forum\Application\Services\CategoryAccessPolicy;
use Modules\Forum\Application\Services\ForumWriter;
use Modules\Forum\Domain\Dice\NumberGenerator;
use Modules\Forum\Domain\Entities\Category;
use Modules\Forum\Domain\Entities\Discussion;
use Modules\Forum\Domain\ValueObjects\CategoryAudience;
use Modules\Forum\Domain\ValueObjects\PostId;
use Modules\Forum\Infrastructure\Persistence\Eloquent\Models\PostModel;
use Modules\Forum\Tests\Support\BuildsCharacters;
use Modules\Forum\Tests\Support\BuildsForumContent;
use Modules\Forum\Tests\Unit\Domain\Dice\FixedNumbers;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\Modules\ModuleTestCase;
use Tests\Support\Time\UsesUtcStorage;

/**
 * Writing as a character: the server checks who a post speaks as every time it is written or
 * edited. Nobody writes as someone else's character, a retired one or one tied to another
 * category; the post shows the character's portrait and name, and its rolls say who throws.
 */
final class PostCharacterEndpointsTest extends ModuleTestCase
{
    use BuildsCharacters;
    use BuildsForumContent;
    use UsesUtcStorage;

    protected ?string $moduleName = 'forum';

    protected bool $autoEnableModule = true;

    private UserModel $author;

    private Category $category;

    private Discussion $discussion;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useUtcStorage();
        $this->app->instance(NumberGenerator::class, new FixedNumbers([17, 4]));
        $this->author = UserModel::factory()->create();
        $this->category = $this->aCategory();
        [$this->discussion] = $this->aDiscussion($this->category, authorId: $this->author->id);
    }

    public function test_a_reply_speaks_as_its_authors_character_and_its_rolls_say_who_throws(): void
    {
        $arwen = $this->aCharacterOf($this->author->id);

        $response = $this->reply([
            $this->roll('1d20+5', 'Ataque'),
            ['type' => 'ooc', 'content' => [$this->roll('1d6')]],
        ], $arwen->id()->value)->assertCreated();

        $html = (string) $response->json('post.html');
        $this->assertSame(1, substr_count($html, 'Arwen tira</span>'));
        $this->assertStringContainsString('<aside class="forum-ooc">', $html);
        $this->assertSame($arwen->id()->value, PostModel::query()->where('author_id', $this->author->id)->whereNotNull('number')->orderByDesc('number')->value('character_id'));
    }

    public function test_a_reply_out_of_character_names_no_character(): void
    {
        $this->aCharacterOf($this->author->id);

        $html = (string) $this->reply([$this->roll('1d20')], null)->assertCreated()->json('post.html');

        $this->assertStringNotContainsString(' tira</span>', $html);
        $this->assertSame(0, PostModel::query()->whereNotNull('character_id')->count());
    }

    public function test_nobody_writes_as_a_character_they_may_not_use(): void
    {
        $stranger = UserModel::factory()->create();
        $foreign = $this->aCharacterOf($stranger->id, 'Ajeno');
        $retired = $this->aCharacterOf($this->author->id, 'Retirado', retired: true);
        $elsewhere = $this->aCharacterOf($this->author->id, 'De otra categoría', boundTo: $this->aCategory('otra'));

        foreach ([$foreign, $retired, $elsewhere] as $character) {
            $this->reply([$this->paragraph('Hola')], $character->id()->value)
                ->assertUnprocessable()
                ->assertJsonPath('reason', 'character_unavailable')
                ->assertJsonValidationErrors('character');
        }
        $this->reply([$this->paragraph('Hola')], 'no-es-un-id')->assertUnprocessable()->assertJsonPath('reason', 'character_unavailable');
        $this->reply([$this->paragraph('Hola')], '9d4f6a2e-1b3c-4d5e-8f70-a1b2c3d4e5f6')->assertUnprocessable();

        // Nothing was written
        $this->assertSame(1, PostModel::query()->count());
    }

    public function test_a_character_tied_to_a_category_speaks_there_and_in_its_subcategories(): void
    {
        $space = $this->aCategoryFor(CategoryAudience::signedIn(), 'espacio', parent: $this->category);
        [$inSpace] = $this->aDiscussion($space, authorId: $this->author->id);
        $tied = $this->aCharacterOf($this->author->id, 'Del foro general', boundTo: $this->category);

        $this->reply([$this->paragraph('Aquí')], $tied->id()->value)->assertCreated();
        $this->reply([$this->paragraph('En el espacio')], $tied->id()->value, $inSpace)->assertCreated();
    }

    public function test_characters_off_means_nobody_writes_as_one(): void
    {
        $arwen = $this->aCharacterOf($this->author->id);
        config(['modules.settings.forum.characters_access' => 'nobody']);

        $this->reply([$this->paragraph('Hola')], $arwen->id()->value)->assertUnprocessable();
    }

    public function test_an_edit_may_switch_to_another_own_character_or_out_of_character_only(): void
    {
        $arwen = $this->aCharacterOf($this->author->id);
        $boromir = $this->aCharacterOf($this->author->id, 'Boromir');
        $foreign = $this->aCharacterOf(UserModel::factory()->create()->id, 'Ajeno');
        $postId = (string) $this->reply([$this->paragraph('Hola')], $arwen->id()->value)->assertCreated()->json('post.id');

        $this->edit($postId, 'Hola de nuevo', ['character' => $foreign->id()->value])->assertUnprocessable()->assertJsonPath('reason', 'character_unavailable');
        $this->assertSame($arwen->id()->value, PostModel::query()->whereKey($postId)->value('character_id'));

        $this->edit($postId, 'Hola de nuevo', ['character' => $boromir->id()->value])->assertOk();
        $this->assertSame($boromir->id()->value, PostModel::query()->whereKey($postId)->value('character_id'));

        $this->edit($postId, 'Hola otra vez', ['character' => null])->assertOk();
        $this->assertNull(PostModel::query()->whereKey($postId)->value('character_id'));
    }

    public function test_an_edit_keeps_a_character_retired_since_but_never_switches_to_one(): void
    {
        $arwen = $this->aCharacterOf($this->author->id);
        $retired = $this->aCharacterOf($this->author->id, 'Retirado', retired: true);
        $postId = (string) $this->reply([$this->paragraph('Hola')], $arwen->id()->value)->assertCreated()->json('post.id');
        $this->actingAs($this->author)->postJson("/foro/personajes/{$arwen->id()->value}/retirar")->assertOk();

        // Without saying, or saying the same one, the post keeps it
        $this->edit($postId, 'Corregido', [])->assertOk();
        $this->edit($postId, 'Corregido otra vez', ['character' => $arwen->id()->value])->assertOk();
        $this->assertSame($arwen->id()->value, PostModel::query()->whereKey($postId)->value('character_id'));

        $this->edit($postId, 'A otro', ['character' => $retired->id()->value])->assertUnprocessable();
    }

    public function test_changing_only_the_character_keeps_a_version_of_who_spoke(): void
    {
        $moderator = $this->anAccount(CategoryAccessPolicy::MODERATE_FORUM);
        $arwen = $this->aCharacterOf($this->author->id);
        $postId = (string) $this->reply([$this->paragraph('Hola')], $arwen->id()->value)->assertCreated()->json('post.id');

        $this->edit($postId, 'Hola', ['character' => null])->assertOk();

        $this->actingAs($moderator)->getJson("/foro/mensajes/{$postId}/versiones")
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.characterName', 'Arwen')
            ->assertJsonPath('data.1.characterName', null);
    }

    public function test_a_moderators_edit_keeps_who_the_post_speaks_as(): void
    {
        $moderator = $this->anAccount(CategoryAccessPolicy::MODERATE_FORUM);
        $arwen = $this->aCharacterOf($this->author->id);
        $mine = $this->aCharacterOf($moderator->id, 'De la moderación');
        $postId = (string) $this->reply([$this->paragraph('Hola')], $arwen->id()->value)->assertCreated()->json('post.id');

        $this->actingAs($moderator)
            ->patchJson("/foro/mensajes/{$postId}", ['document' => $this->doc([$this->paragraph('Moderado')]), 'character' => $mine->id()->value])
            ->assertOk();

        $this->assertSame($arwen->id()->value, PostModel::query()->whereKey($postId)->value('character_id'));
    }

    public function test_a_discussion_starts_as_a_character(): void
    {
        $arwen = $this->aCharacterOf($this->author->id);
        $foreign = $this->aCharacterOf(UserModel::factory()->create()->id, 'Ajeno');

        $this->actingAs($this->author)->postJson('/foro/temas', [
            'category' => $this->category->id()->value,
            'title' => 'Prólogo de la campaña',
            'document' => $this->doc([$this->paragraph('Érase una vez')]),
            'character' => $foreign->id()->value,
        ])->assertUnprocessable()->assertJsonPath('reason', 'character_unavailable');

        $this->actingAs($this->author)->postJson('/foro/temas', [
            'category' => $this->category->id()->value,
            'title' => 'Prólogo de la campaña',
            'document' => $this->doc([$this->paragraph('Érase una vez')]),
            'character' => $arwen->id()->value,
        ])->assertCreated();

        $this->assertSame(1, PostModel::query()->where('character_id', $arwen->id()->value)->count());
    }

    public function test_the_page_shows_the_character_and_offers_the_last_one_used(): void
    {
        $arwen = $this->aCharacterOf($this->author->id);
        $this->aCharacterOf($this->author->id, 'Boromir');
        $this->aCharacterOf($this->author->id, 'Retirado', retired: true);
        $this->reply([$this->paragraph('En personaje')], $arwen->id()->value)->assertCreated();
        // A later post out of character does not change what is offered
        $this->reply([$this->paragraph('Fuera de personaje')], null)->assertCreated();

        $this->actingAs($this->author)->get($this->discussionUrl())
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('posts.1.character.name', 'Arwen')
                ->where('posts.1.character.initials', 'A')
                ->where('posts.1.character.actions', null)
                ->where('posts.1.characterId', $arwen->id()->value)
                ->where('posts.2.character', null)
                ->has('reply.characters.items', 2)
                ->where('reply.characters.items.0.name', 'Arwen')
                ->where('reply.characters.suggestedId', $arwen->id()->value)
                ->etc());
    }

    public function test_the_reply_picker_names_the_discussions_category(): void
    {
        // «Hablar como» compares it with where a character just created is tied: without it, one
        // tied to this very category is taken for one tied elsewhere and not chosen
        $this->actingAs($this->author)->get($this->discussionUrl())
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('reply.characters.categoryId', $this->category->id()->value)
                ->etc());
    }

    public function test_a_character_tied_to_a_category_shows_only_to_whoever_reads_it(): void
    {
        $members = $this->aCategoryFor(CategoryAudience::associationMembers(), 'socios');
        $tied = $this->aCharacterOf($this->author->id, 'Secreto', boundTo: $members);
        // Written there and moved here since
        app(ForumWriter::class)->reply($this->discussion->id(), PostId::generate(), $this->author->id, self::aBody('Hola'), new DateTimeImmutable('2026-11-12 12:00:00'), false, $tied->id());

        $this->get($this->discussionUrl())
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('posts.1.character', null)->where('posts.1.authorName', $this->author->name)->etc());

        $this->actingAs($this->anAccount(CategoryAccessPolicy::MODERATE_FORUM))->get($this->discussionUrl())
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('posts.1.character.name', 'Secreto')
                ->whereNot('posts.1.character.actions', null)
                ->etc());
    }

    private function discussionUrl(): string
    {
        $number = (int) $this->discussion->number();

        return "/foro/t/{$number}-".str($this->discussion->title()->value)->slug();
    }

    /**
     * @param  list<array<string, mixed>>  $content
     * @return TestResponse<Response>
     */
    private function reply(array $content, ?string $characterId, ?Discussion $discussion = null): TestResponse
    {
        $discussion ??= $this->discussion;

        return $this->actingAs($this->author)->postJson("/foro/temas/{$discussion->id()->value}/mensajes", ['document' => $this->doc($content), 'character' => $characterId]);
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return TestResponse<Response>
     */
    private function edit(string $postId, string $text, array $extra): TestResponse
    {
        return $this->actingAs($this->author)->patchJson("/foro/mensajes/{$postId}", ['document' => $this->doc([$this->paragraph($text)]), ...$extra]);
    }

    /**
     * @param  list<array<string, mixed>>  $content
     * @return array<string, mixed>
     */
    private function doc(array $content): array
    {
        return ['type' => 'doc', 'content' => $content];
    }

    /**
     * @return array<string, mixed>
     */
    private function paragraph(string $text): array
    {
        return ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $text]]];
    }

    /**
     * @return array<string, mixed>
     */
    private function roll(string $expression, ?string $reason = null): array
    {
        return ['type' => 'roll', 'attrs' => ['expression' => $expression, 'reason' => $reason, 'rollId' => null]];
    }
}
