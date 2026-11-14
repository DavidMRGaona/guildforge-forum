<?php

declare(strict_types=1);

namespace Modules\Forum\Tests\Feature\Http;

use App\Application\Authorization\Services\AuthorizationServiceInterface;
use App\Infrastructure\Persistence\Eloquent\Models\RoleModel;
use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Modules\Forum\Application\Services\ForumWriter;
use Modules\Forum\Domain\Entities\Category;
use Modules\Forum\Domain\Entities\Discussion;
use Modules\Forum\Domain\Repositories\CategoryRepositoryInterface;
use Modules\Forum\Domain\Repositories\MemberTitleRepositoryInterface;
use Modules\Forum\Domain\ValueObjects\CategoryAudience;
use Modules\Forum\Domain\ValueObjects\CategoryModerators;
use Modules\Forum\Domain\ValueObjects\MemberTitle;
use Modules\Forum\Domain\ValueObjects\PostId;
use Modules\Forum\Tests\Support\BuildsCharacters;
use Modules\Forum\Tests\Support\BuildsForumContent;
use Tests\Support\Modules\ModuleTestCase;
use Tests\Support\Time\UsesUtcStorage;

/**
 * Each post carries its author's title, read when the page is drawn: for every reader, never in
 * character and never for an account that is no longer active. Only whoever moderates the whole
 * forum gets where to change titles.
 */
final class PostTitlesTest extends ModuleTestCase
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
        $this->author = UserModel::factory()->create();
        $this->titleOf($this->author, 'Bardo');
        $this->category = $this->aCategory();
        [$this->discussion] = $this->aDiscussion($this->category, authorId: $this->author->id);
    }

    public function test_a_post_shows_its_authors_title(): void
    {
        $this->get($this->discussionUrl())
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('posts.0.authorTitle', 'Bardo')->etc());
    }

    public function test_no_title_in_character(): void
    {
        $arwen = $this->aCharacterOf($this->author->id);
        app(ForumWriter::class)->reply($this->discussion->id(), PostId::generate(), $this->author->id, self::aBody('En personaje'), new DateTimeImmutable('2026-11-12 12:00:00'), false, $arwen->id());

        $this->get($this->discussionUrl())
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('posts.1.character.name', 'Arwen')
                ->where('posts.1.authorTitle', null)
                ->where('posts.0.authorTitle', 'Bardo')
                ->etc());
    }

    public function test_no_title_for_a_deactivated_or_anonymized_author(): void
    {
        $this->author->delete();
        $this->get($this->discussionUrl())
            ->assertInertia(fn (AssertableInertia $page) => $page->where('posts.0.authorTitle', null)->etc());

        $this->author->restore();
        $this->author->forceFill(['anonymized_at' => CarbonImmutable::now()])->save();
        $this->get($this->discussionUrl())
            ->assertInertia(fn (AssertableInertia $page) => $page->where('posts.0.authorTitle', null)->etc());
    }

    public function test_titles_of_a_page_are_read_at_once(): void
    {
        foreach (['Juglar', 'Guardián'] as $index => $title) {
            $someone = UserModel::factory()->create();
            $this->titleOf($someone, $title);
            app(ForumWriter::class)->reply($this->discussion->id(), PostId::generate(), $someone->id, self::aBody("Respuesta {$index}"), new DateTimeImmutable("2026-11-12 12:0{$index}:00"), false);
        }

        DB::enableQueryLog();
        $this->get($this->discussionUrl())->assertOk();
        $titleQueries = array_filter(DB::getQueryLog(), static fn (array $query): bool => str_contains($query['query'], 'forum_member_titles'));
        DB::disableQueryLog();

        $this->assertCount(1, $titleQueries);
    }

    public function test_only_a_forum_moderator_gets_the_title_url(): void
    {
        $this->actingAs($this->anAccount('forum:forum.moderate'))->get($this->discussionUrl())
            ->assertInertia(fn (AssertableInertia $page) => $page->where('moderation.titleUrl', route('forum.titles.store'))->etc());
    }

    public function test_whoever_moderates_only_the_category_gets_no_title_url(): void
    {
        $role = RoleModel::query()->create(['name' => 'tablemasters', 'display_name' => 'Tablemasters']);
        $this->category->changeAccess(CategoryAudience::everyone(), CategoryAudience::signedIn(), new CategoryModerators([$role->id]));
        app(CategoryRepositoryInterface::class)->save($this->category);
        $keeper = $this->anAccount();
        $keeper->roles()->attach($role->id);
        app(AuthorizationServiceInterface::class)->invalidateCacheFor($keeper);

        // The keeper moderates this category (the menu is there) but not the whole forum
        $this->actingAs($keeper)->get($this->discussionUrl())
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('moderation.actionsUrl', fn (mixed $url): bool => is_string($url))
                ->where('moderation.titleUrl', null)
                ->etc());
    }

    private function titleOf(UserModel $person, string $title): void
    {
        $value = MemberTitle::fromInput($title);
        $this->assertInstanceOf(MemberTitle::class, $value);
        app(MemberTitleRepositoryInterface::class)->save($person->id, $value, $person->id, new DateTimeImmutable('2026-11-14 12:00:00'));
    }

    private function discussionUrl(): string
    {
        $number = (int) $this->discussion->number();

        return "/foro/t/{$number}-".str($this->discussion->title()->value)->slug();
    }
}
