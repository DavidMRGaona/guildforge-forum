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
use Modules\Forum\Domain\Entities\Character;
use Modules\Forum\Domain\Entities\Discussion;
use Modules\Forum\Domain\ValueObjects\CategoryAudience;
use Modules\Forum\Domain\ValueObjects\DiscussionId;
use Modules\Forum\Domain\ValueObjects\DiscussionTitle;
use Modules\Forum\Domain\ValueObjects\PostBody;
use Modules\Forum\Domain\ValueObjects\PostId;
use Modules\Forum\Infrastructure\Persistence\Eloquent\Models\DiceRollModel;
use Modules\Forum\Infrastructure\Persistence\Eloquent\Models\PostModel;
use Modules\Forum\Tests\Support\BuildsCharacters;
use Modules\Forum\Tests\Support\BuildsForumContent;
use Modules\Forum\Tests\Unit\Domain\Dice\FixedNumbers;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\Modules\ModuleTestCase;
use Tests\Support\Time\UsesUtcStorage;

/**
 * A post that speaks, or spoke, as a personal character never names its writer by the account
 * name (forum#139): only by their public name. Without one, the character is named while it
 * exists and «Personaje eliminado» once moderation deleted it, with nothing that points at the
 * account. Whoever wrote it and moderation still see the account, as with shared characters.
 */
final class CharacterWriterNamesTest extends ModuleTestCase
{
    use BuildsCharacters;
    use BuildsForumContent;
    use UsesUtcStorage;

    private const string ACCOUNT_NAME = 'Laura Gómez Ruiz';

    protected ?string $moduleName = 'forum';

    protected bool $autoEnableModule = true;

    /** Wrote as a character before a public name was required */
    private UserModel $player;

    private UserModel $reader;

    private Category $category;

    private Discussion $discussion;

    private Character $arwen;

    private PostId $arwenPost;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useUtcStorage();
        $this->player = UserModel::factory()->create(['name' => self::ACCOUNT_NAME, 'display_name' => null]);
        $this->player->forceFill(['avatar_public_id' => 'avatars/laura'])->save();
        $this->reader = UserModel::factory()->create();
        $this->category = $this->aCategory();
        [$this->discussion] = $this->aDiscussion($this->category, authorId: UserModel::factory()->create()->id);
        $this->arwen = $this->aCharacterOf($this->player->id);
        $this->arwenPost = PostId::generate();
        app(ForumWriter::class)->reply($this->discussion->id(), $this->arwenPost, strtolower($this->player->id), self::aBody('En personaje'), new DateTimeImmutable('2026-11-12 12:00:00'), false, $this->arwen->id());
    }

    public function test_a_post_as_a_character_names_the_character_and_not_who_plays_it(): void
    {
        $response = $this->actingAs($this->reader)->get($this->discussionUrl())->assertOk();

        $response->assertInertia(fn (AssertableInertia $page) => $page
            ->where('posts.1.character.name', 'Arwen')
            ->where('posts.1.authorName', null)
            // Not even the id: their posts out of character carry it with their name
            ->where('posts.1.authorId', null)
            ->where('posts.1.writerUnnamed', true)
            ->where('posts.1.isStarter', false)
            ->etc());
        $this->assertNameNowhere($response);
        $this->assertIdNowhere($response);
    }

    public function test_deleting_the_character_marks_its_posts_and_they_name_nobody(): void
    {
        $this->deleteArwen();

        $this->assertSame(1, PostModel::query()->where('character_removed', true)->count());
        $response = $this->actingAs($this->reader)->get($this->discussionUrl())->assertOk();

        $response->assertInertia(fn (AssertableInertia $page) => $page
            ->where('posts.1.character', null)
            ->where('posts.1.authorName', 'Personaje eliminado')
            ->where('posts.1.authorId', null)
            ->where('posts.1.authorAvatarPublicId', null)
            ->where('posts.1.authorTitle', null)
            ->where('posts.1.isStarter', false)
            ->etc());
        $this->assertNameNowhere($response);
        $this->assertIdNowhere($response);
    }

    public function test_a_guest_sees_the_same_as_a_signed_in_reader(): void
    {
        $this->deleteArwen();
        $this->app['auth']->forgetGuards();

        $response = $this->get($this->discussionUrl())->assertOk();

        $response->assertInertia(fn (AssertableInertia $page) => $page->where('posts.1.authorName', 'Personaje eliminado')->etc());
        $this->assertNameNowhere($response);
    }

    public function test_with_a_public_name_the_posts_show_it_before_and_after_the_deletion(): void
    {
        $this->player->forceFill(['display_name' => 'Lau'])->save();

        $this->actingAs($this->reader)->get($this->discussionUrl())
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('posts.1.character.name', 'Arwen')
                ->where('posts.1.authorName', 'Lau')
                ->etc());

        $this->deleteArwen();

        $this->actingAs($this->reader)->get($this->discussionUrl())
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('posts.1.authorName', 'Lau')
                ->where('posts.1.authorId', strtolower($this->player->id))
                ->where('posts.1.writerUnnamed', false)
                ->where('posts.1.authorAvatarPublicId', 'avatars/laura')
                ->etc());
    }

    public function test_moderation_and_the_writer_still_see_the_account(): void
    {
        $this->deleteArwen();

        foreach ([$this->anAccount(CategoryAccessPolicy::MODERATE_FORUM), $this->player] as $viewer) {
            $this->actingAs($viewer)->get($this->discussionUrl())
                ->assertInertia(fn (AssertableInertia $page) => $page
                    ->where('posts.1.authorId', strtolower($this->player->id))
                    ->where('posts.1.authorName', self::ACCOUNT_NAME)
                    ->etc());
        }
    }

    public function test_a_character_the_reader_may_not_see_is_not_named_and_neither_is_the_account(): void
    {
        // Tied to a category the reader cannot view: the post moved here keeps speaking as it
        $hidden = $this->aCategoryFor(CategoryAudience::associationMembers(), 'oculta');
        $tied = $this->aCharacterOf($this->player->id, 'Elrond', boundTo: $hidden);
        app(ForumWriter::class)->reply($this->discussion->id(), PostId::generate(), strtolower($this->player->id), self::aBody('Desde Rivendel'), new DateTimeImmutable('2026-11-12 13:00:00'), false, $tied->id());

        $response = $this->actingAs($this->reader)->get($this->discussionUrl())->assertOk();

        $response->assertInertia(fn (AssertableInertia $page) => $page
            ->where('posts.2.character', null)
            ->where('posts.2.authorName', 'Personaje no disponible')
            ->where('posts.2.authorId', null)
            ->etc());
        $this->assertNameNowhere($response);
        $this->assertStringNotContainsString('Elrond', (string) json_encode($response->viewData('page'), JSON_UNESCAPED_UNICODE));
    }

    public function test_a_post_out_of_character_shows_the_account_as_it_always_did(): void
    {
        app(ForumWriter::class)->reply($this->discussion->id(), PostId::generate(), strtolower($this->player->id), self::aBody('Fuera de personaje'), new DateTimeImmutable('2026-11-12 13:00:00'), false);

        $this->actingAs($this->reader)->get($this->discussionUrl())
            ->assertInertia(fn (AssertableInertia $page) => $page->where('posts.2.authorName', self::ACCOUNT_NAME)->etc());
    }

    public function test_the_lists_name_the_character_and_then_say_it_was_deleted(): void
    {
        $started = Discussion::start(DiscussionId::generate(), $this->category->id(), strtolower($this->player->id), new DiscussionTitle('Diario de Arwen'), new DateTimeImmutable('2026-11-12 14:00:00'));
        app(ForumWriter::class)->startDiscussion($started, PostId::generate(), self::aBody('Primer día'), false, $this->arwen->id());

        $this->actingAs($this->reader)->get('/foro')->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('categories.0.lastPost.authorName', 'Arwen')->etc());
        $category = $this->actingAs($this->reader)->get('/foro/c/general')->assertOk();
        $category->assertInertia(fn (AssertableInertia $page) => $page
            ->where('discussions.items.0.title', 'Diario de Arwen')
            ->where('discussions.items.0.authorName', 'Arwen')
            ->where('discussions.items.0.lastPost.authorName', 'Arwen')
            ->where('discussions.items.1.lastPost.authorName', 'Arwen')
            ->etc());
        $this->assertNameNowhere($category);

        $this->deleteArwen();

        $this->actingAs($this->reader)->get('/foro')
            ->assertInertia(fn (AssertableInertia $page) => $page->where('categories.0.lastPost.authorName', 'Personaje eliminado')->etc());
        $category = $this->actingAs($this->reader)->get('/foro/c/general');
        $category->assertInertia(fn (AssertableInertia $page) => $page
            ->where('discussions.items.0.authorName', 'Personaje eliminado')
            ->where('discussions.items.0.lastPost.authorName', 'Personaje eliminado')
            ->where('discussions.items.1.lastPost.authorName', 'Personaje eliminado')
            ->etc());
        $this->assertNameNowhere($category);
    }

    public function test_the_lists_use_the_public_name_when_there_is_one(): void
    {
        $this->player->forceFill(['display_name' => 'Lau'])->save();
        $this->deleteArwen();

        $this->actingAs($this->reader)->get('/foro/c/general')
            ->assertInertia(fn (AssertableInertia $page) => $page->where('discussions.items.0.lastPost.authorName', 'Lau')->etc());
    }

    public function test_the_author_filter_leaves_out_their_posts_as_a_character(): void
    {
        app(ForumWriter::class)->reply($this->discussion->id(), PostId::generate(), strtolower($this->player->id), self::aBody('Fuera de personaje'), new DateTimeImmutable('2026-11-12 13:00:00'), false);
        $filtered = $this->discussionUrl().'?autor='.$this->player->id;

        $this->actingAs($this->reader)->get($filtered)
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('posts', 1)
                ->where('posts.0.html', fn (string $html): bool => str_contains($html, 'Fuera de personaje'))
                ->where('filter.count', 1)
                ->etc());

        $this->deleteArwen();

        $this->actingAs($this->reader)->get($filtered)
            ->assertInertia(fn (AssertableInertia $page) => $page->has('posts', 1)->where('filter.count', 1)->etc());
        // Their own messages are all theirs to see
        $this->actingAs($this->player)->get($filtered)
            ->assertInertia(fn (AssertableInertia $page) => $page->has('posts', 2)->etc());
    }

    public function test_a_quotation_names_the_character_and_then_says_it_was_deleted(): void
    {
        $quote = ['type' => 'postQuote', 'attrs' => ['postId' => $this->arwenPost->value], 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'En personaje']]]]];
        app(ForumWriter::class)->reply($this->discussion->id(), PostId::generate(), strtolower($this->reader->id), new PostBody(['type' => 'doc', 'content' => [$quote]], 'En personaje'), new DateTimeImmutable('2026-11-12 13:00:00'), false);

        $this->actingAs($this->reader)->get($this->discussionUrl())
            ->assertInertia(fn (AssertableInertia $page) => $page->where('posts.2.html', fn (string $html): bool => str_contains($html, 'Arwen escribió:'))->etc());

        $this->deleteArwen();

        $response = $this->actingAs($this->reader)->get($this->discussionUrl());
        $response->assertInertia(fn (AssertableInertia $page) => $page->where('posts.2.html', fn (string $html): bool => str_contains($html, 'Personaje eliminado escribió:'))->etc());
        $this->assertNameNowhere($response);
    }

    public function test_a_hidden_roll_its_writer_revealed_in_character_never_names_the_account(): void
    {
        $this->app->instance(NumberGenerator::class, new FixedNumbers([4, 2]));
        // Written and revealed while they had a public name, which they took away later
        $this->player->forceFill(['display_name' => 'Lau'])->save();
        $roll = ['type' => 'roll', 'attrs' => ['expression' => '2d6', 'reason' => 'Sigilo', 'rollId' => null, 'hidden' => true]];
        $this->actingAs($this->player)
            ->postJson("/foro/temas/{$this->discussion->id()->value}/mensajes", ['document' => ['type' => 'doc', 'content' => [$roll]], 'character' => $this->arwen->id()->value])
            ->assertCreated();
        $this->actingAs($this->player)->postJson('/foro/tiradas/'.DiceRollModel::query()->sole()->id.'/revelar')->assertOk();
        $this->player->forceFill(['display_name' => null])->save();

        $response = $this->actingAs($this->reader)->get($this->discussionUrl());
        $response->assertInertia(fn (AssertableInertia $page) => $page->where('posts.2.html', fn (string $html): bool => str_contains($html, 'revelada por Arwen el'))->etc());
        $this->assertNameNowhere($response);

        $this->deleteArwen();

        $response = $this->actingAs($this->reader)->get($this->discussionUrl());
        $response->assertInertia(fn (AssertableInertia $page) => $page->where('posts.2.html', fn (string $html): bool => str_contains($html, 'revelada por Personaje eliminado el'))->etc());
        $this->assertNameNowhere($response);
        // Moderation still reads who did it
        $this->actingAs($this->anAccount(CategoryAccessPolicy::MODERATE_FORUM))->get($this->discussionUrl())
            ->assertInertia(fn (AssertableInertia $page) => $page->where('posts.2.html', fn (string $html): bool => str_contains($html, 'revelada por '.self::ACCOUNT_NAME))->etc());
    }

    private function deleteArwen(): void
    {
        $this->actingAs($this->anAccount(CategoryAccessPolicy::MODERATE_FORUM))
            ->deleteJson("/foro/personajes/{$this->arwen->id()->value}")
            ->assertNoContent();
    }

    /**
     * @param  TestResponse<Response>  $response
     */
    private function assertNameNowhere(TestResponse $response): void
    {
        $page = json_encode($response->viewData('page'), JSON_UNESCAPED_UNICODE);
        $this->assertIsString($page);
        $this->assertStringNotContainsString('Laura', $page);
    }

    /**
     * @param  TestResponse<Response>  $response
     */
    private function assertIdNowhere(TestResponse $response): void
    {
        $page = json_encode($response->viewData('page'), JSON_UNESCAPED_UNICODE);
        $this->assertIsString($page);
        $this->assertStringNotContainsStringIgnoringCase($this->player->id, $page);
    }

    private function discussionUrl(): string
    {
        $number = (int) $this->discussion->number();

        return "/foro/t/{$number}-".str($this->discussion->title()->value)->slug();
    }
}
