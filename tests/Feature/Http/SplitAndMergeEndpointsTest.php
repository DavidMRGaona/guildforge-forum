<?php

declare(strict_types=1);

namespace Modules\Forum\Tests\Feature\Http;

use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use DateTimeImmutable;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Forum\Application\Contracts\DiscussionReadsInterface;
use Modules\Forum\Application\Services\ForumModeration;
use Modules\Forum\Application\Services\ForumWriter;
use Modules\Forum\Domain\Entities\Category;
use Modules\Forum\Domain\Entities\Discussion;
use Modules\Forum\Domain\Repositories\CategoryRepositoryInterface;
use Modules\Forum\Domain\Repositories\DiscussionRepositoryInterface;
use Modules\Forum\Domain\Repositories\SubjectDiscussionRepositoryInterface;
use Modules\Forum\Domain\ValueObjects\CategoryAudience;
use Modules\Forum\Domain\ValueObjects\CategoryModerators;
use Modules\Forum\Domain\ValueObjects\PostId;
use Modules\Forum\Domain\ValueObjects\PublicationKey;
use Modules\Forum\Http\Controllers\ModerationController;
use Modules\Forum\Infrastructure\Persistence\Eloquent\Models\ModerationLogModel;
use Modules\Forum\Tests\Support\BuildsForumContent;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\Modules\ModuleTestCase;
use Tests\Support\Time\UsesUtcStorage;

/**
 * Splitting and merging from the discussion's moderation menu, and the addresses that lead
 * from where posts were to where they are.
 */
final class SplitAndMergeEndpointsTest extends ModuleTestCase
{
    use BuildsForumContent;
    use UsesUtcStorage;

    private const string MODERATE = 'forum:forum.moderate';

    protected ?string $moduleName = 'forum';

    protected bool $autoEnableModule = true;

    private Category $general;

    private UserModel $moderator;

    private Discussion $discussion;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useUtcStorage();
        $this->general = $this->aCategoryFor(CategoryAudience::everyone(), 'general');
        $this->moderator = $this->anAccount(self::MODERATE);
        [$this->discussion] = $this->aDiscussion($this->general, '2026-11-10 18:00:00', title: 'Mesa del viernes');
        for ($index = 2; $index <= 5; $index++) {
            $this->aReply($this->discussion, sprintf('2026-11-10 18:%02d:00', $index * 10), text: "Mensaje {$index}");
        }
    }

    public function test_the_menu_offers_splitting_and_merging_to_moderators_only(): void
    {
        $url = '/foro/t/1-mesa-del-viernes';
        $id = $this->discussion->id()->value;

        $this->actingAs($this->anAccount())->get($url)->assertInertia(fn (Assert $page) => $page->where('moderation', null));
        $this->actingAs($this->moderator)->get($url)->assertInertia(fn (Assert $page) => $page
            ->where('moderation.splitUrl', route('forum.moderation.discussions.split', ['discussion' => $id]))
            ->where('moderation.mergeUrl', route('forum.moderation.discussions.merge', ['discussion' => $id]))
            ->where('moderation.mergeTargetUrl', route('forum.moderation.discussions.merge-target', ['discussion' => $id]))
            ->where('moderation.splitCategories', [['id' => $this->general->id()->value, 'name' => 'General', 'parentName' => null]])
            ->where('moderation.limits.splitMax', ForumModeration::MAX_SPLIT));
    }

    public function test_a_publications_discussion_offers_neither(): void
    {
        $record = app(SubjectDiscussionRepositoryInterface::class)->lockOrCreate(new PublicationKey('game-table', '0d2f3c4b-1a2b-4c3d-8e9f-0a1b2c3d4e5f'));
        $record->announce($this->discussion->id(), PostId::generate(), [], true);
        app(SubjectDiscussionRepositoryInterface::class)->save($record);

        $this->actingAs($this->moderator)->get('/foro/t/1-mesa-del-viernes')->assertInertia(fn (Assert $page) => $page
            ->where('moderation.splitUrl', null)
            ->where('moderation.mergeUrl', null)
            ->where('moderation.splitCategories', []));

        $this->split(['numbers' => [2], 'title' => 'Aparte', 'category' => $this->general->id()->value, 'confirmed' => true])
            ->assertStatus(422)
            ->assertJsonPath('reason', 'automatic_discussion');
    }

    public function test_splitting_answers_the_new_discussion_and_asks_for_the_box_first(): void
    {
        $fields = ['numbers' => [3, 4], 'title' => 'Otro asunto', 'category' => $this->general->id()->value];

        $this->split($fields)->assertStatus(422)->assertJsonValidationErrors('confirmed');
        $this->assertSame(0, ModerationLogModel::query()->count());

        $this->split([...$fields, 'confirmed' => true, 'reason' => 'Se desvió'])
            ->assertOk()
            ->assertJsonPath('url', '/foro/t/2-otro-asunto');
        $this->assertSame(1, ModerationLogModel::query()->count());
    }

    public function test_whoever_does_not_moderate_cannot_split_nor_merge(): void
    {
        [$into] = $this->aDiscussion($this->general, '2026-11-11 10:00:00', title: 'Destino');
        $reader = $this->anAccount();

        $this->split(['numbers' => [2], 'title' => 'Aparte', 'category' => $this->general->id()->value, 'confirmed' => true], $reader)->assertForbidden();
        $this->merge(['target' => $into->id()->value, 'confirmed' => true], $reader)->assertForbidden();
        $this->actingAs($reader)->getJson($this->mergeTargetUrl('2'))->assertForbidden();
    }

    public function test_a_bad_selection_is_refused_with_its_reason(): void
    {
        $this->split(['numbers' => [1, 2], 'title' => 'Aparte', 'category' => $this->general->id()->value, 'confirmed' => true])
            ->assertStatus(422)
            ->assertJsonValidationErrors('numbers.0');
        $this->split(['numbers' => [2, 9], 'title' => 'Aparte', 'category' => $this->general->id()->value, 'confirmed' => true])
            ->assertStatus(422)
            ->assertJsonPath('reason', 'split_selection');
    }

    public function test_the_merge_target_is_looked_up_by_number_or_address(): void
    {
        [$into] = $this->aDiscussion($this->general, '2026-11-11 10:00:00', title: 'Destino');

        foreach (['2', '#2', '/foro/t/2-destino', 'https://example.com/foro/t/2-destino/3'] as $typed) {
            $this->actingAs($this->moderator)->getJson($this->mergeTargetUrl($typed))
                ->assertOk()
                ->assertJsonPath('discussion.id', $into->id()->value)
                ->assertJsonPath('discussion.title', 'Destino')
                ->assertJsonPath('discussion.categoryName', 'General');
        }

        foreach (['1', '99', 'nada'] as $typed) {
            $this->actingAs($this->moderator)->getJson($this->mergeTargetUrl($typed))
                ->assertStatus(422)
                ->assertJsonPath('reason', $typed === '1' ? 'merge_target' : 'merge_not_found');
        }
    }

    /**
     * @return array<string, array{string, int|null}>
     */
    public static function typedNumbers(): array
    {
        return [
            'number' => ['42', 42],
            'hash' => ['#42', 42],
            'address' => ['/foro/t/42-mesa', 42],
            'address of a post' => ['https://example.com/foro/t/42-mesa/7', 42],
            'bare address' => ['/foro/t/42', 42],
            'zero' => ['0', null],
            'words' => ['mesa', null],
            'another page' => ['/foro/c/42', null],
        ];
    }

    #[DataProvider('typedNumbers')]
    public function test_a_discussion_number_is_read_from_what_was_typed(string $typed, ?int $expected): void
    {
        $this->assertSame($expected, ModerationController::discussionNumber($typed));
    }

    public function test_merging_answers_the_discussion_it_joined(): void
    {
        [$into] = $this->aDiscussion($this->general, '2026-11-11 10:00:00', title: 'Destino');

        $this->merge(['target' => $into->id()->value])->assertStatus(422)->assertJsonValidationErrors('confirmed');

        $this->merge(['target' => $into->id()->value, 'confirmed' => true])
            ->assertOk()
            ->assertJsonPath('url', '/foro/t/2-destino');
        $this->assertNull(app(DiscussionRepositoryInterface::class)->find($this->discussion->id()));
    }

    public function test_old_addresses_lead_to_the_new_place_with_a_permanent_redirect(): void
    {
        $this->split(['numbers' => [3, 5], 'title' => 'Otro asunto', 'category' => $this->general->id()->value, 'confirmed' => true])->assertOk();

        $this->get('/foro/t/1-mesa-del-viernes/5')->assertStatus(301)->assertRedirect('/foro/t/2-otro-asunto/2');
        // Even with a stale slug
        $this->get('/foro/t/1-otra-cosa/3')->assertStatus(301)->assertRedirect('/foro/t/2-otro-asunto');
        // What did not move stays where it was
        $this->get('/foro/t/1-mesa-del-viernes/4')->assertOk();

        [$into] = $this->aDiscussion($this->general, '2026-11-11 10:00:00', title: 'Destino');
        app(ForumModeration::class)->merge($this->moderator, $this->discussion->id(), $into->id());

        $this->get('/foro/t/1-mesa-del-viernes')->assertStatus(301)->assertRedirect('/foro/t/3-destino');
        $this->get('/foro/t/1-mesa-del-viernes/4')->assertStatus(301)->assertRedirect('/foro/t/3-destino/4');
        // A post that had moved before follows it
        $this->get('/foro/t/1-mesa-del-viernes/5')->assertStatus(301)->assertRedirect('/foro/t/2-otro-asunto/2');
    }

    public function test_whoever_cannot_read_where_it_went_is_not_told(): void
    {
        $staff = $this->aCategoryFor(CategoryAudience::everyone(), 'equipo');
        app(ForumModeration::class)->split($this->moderator, $this->discussion->id(), [5], 'Cosas del equipo', $staff->id());
        $staff->changeAccess(CategoryAudience::signedIn(), CategoryAudience::signedIn(), CategoryModerators::none());
        app(CategoryRepositoryInterface::class)->save($staff);

        // A guest lands on the last post there is, followed by the gap, which names no discussion
        $this->get('/foro/t/1-mesa-del-viernes/5')->assertStatus(301)->assertRedirect('/foro/t/1-mesa-del-viernes/4');
        $this->get('/foro/t/1-mesa-del-viernes/4')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Forum/Discussion', false)
                ->where('moved', [['fromNumber' => 5, 'throughNumber' => 5, 'count' => 1, 'title' => null, 'url' => null]]));
        $this->actingAs($this->anAccount())->get('/foro/t/1-mesa-del-viernes/5')->assertRedirect('/foro/t/2-cosas-del-equipo');
    }

    public function test_the_page_says_where_its_posts_went_and_pages_stay_fixed_runs_of_numbers(): void
    {
        config(['modules.settings.forum.posts_per_page' => 10]);
        [$long] = $this->aDiscussion($this->general, '2026-11-01 10:00:00', title: 'Larga');
        for ($index = 2; $index <= 25; $index++) {
            $this->aReply($long, sprintf('2026-11-01 10:%02d:00', $index), text: "Mensaje {$index}");
        }
        app(ForumModeration::class)->split($this->moderator, $long->id(), [10, 11], 'Otro asunto', $this->general->id());

        // Page 1 holds numbers 1 to 10: nine posts, and where 10 went
        $this->get('/foro/t/2-larga')->assertInertia(fn (Assert $page) => $page
            ->has('posts', 9)
            ->where('posts.8.number', 9)
            ->where('moved', [['fromNumber' => 10, 'throughNumber' => 10, 'count' => 1, 'title' => 'Otro asunto', 'url' => '/foro/t/3-otro-asunto']])
            ->where('pagination.pageCount', 3)
            // Number 11 moved, so its address leads away: the next page goes by its first post
            ->where('pagination.nextUrl', '/foro/t/2-larga/12'));

        // Number 11 moved: its address leads there; page 2 (11 to 20) holds 12 to 20 and where 11 went
        $this->get('/foro/t/2-larga/11')->assertStatus(301)->assertRedirect('/foro/t/3-otro-asunto/2');
        $this->get('/foro/t/2-larga/12')->assertInertia(fn (Assert $page) => $page
            ->has('posts', 9)
            ->where('posts.0.number', 12)
            ->where('moved', [['fromNumber' => 11, 'throughNumber' => 11, 'count' => 1, 'title' => 'Otro asunto', 'url' => '/foro/t/3-otro-asunto/2']])
            ->where('pagination.page', 2));
        $this->get('/foro/t/2-larga/21')->assertInertia(fn (Assert $page) => $page
            ->has('posts', 5)
            ->where('posts.0.number', 21)
            ->where('moved', []));
    }

    public function test_a_page_whose_numbers_all_moved_shows_where_they_went_and_the_way_on(): void
    {
        config(['modules.settings.forum.posts_per_page' => 10]);
        $long = $this->longDiscussion();
        app(ForumModeration::class)->split($this->moderator, $long->id(), range(11, 20), 'Otro asunto', $this->general->id());

        $this->get('/foro/t/2-larga')->assertInertia(fn (Assert $page) => $page->where('pagination.nextUrl', '/foro/t/2-larga?pagina=2'));
        $this->get('/foro/t/2-larga?pagina=2')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('posts', 0)
                ->where('moved', [['fromNumber' => 11, 'throughNumber' => 20, 'count' => 10, 'title' => 'Otro asunto', 'url' => '/foro/t/3-otro-asunto']])
                ->where('pagination.page', 2)
                ->where('pagination.previousUrl', '/foro/t/2-larga')
                ->where('pagination.nextUrl', '/foro/t/2-larga/21')
                ->where('focusNumber', null)
                ->where('seo.canonicalUrl', url('/foro/t/2-larga?pagina=2')));

        // A page asked for by its place that has posts goes by its usual address
        $this->get('/foro/t/2-larga?pagina=1')->assertStatus(301)->assertRedirect('/foro/t/2-larga');
        $this->get('/foro/t/2-larga?pagina=3')->assertStatus(301)->assertRedirect('/foro/t/2-larga/21');
    }

    public function test_the_first_unread_post_skips_what_moved_away(): void
    {
        config(['modules.settings.forum.posts_per_page' => 10]);
        $long = $this->longDiscussion();
        app(ForumModeration::class)->split($this->moderator, $long->id(), [10, 11], 'Otro asunto', $this->general->id());
        $reader = $this->anAccount();
        app(DiscussionReadsInterface::class)->advance(strtolower($reader->id), $long->id(), 9, new DateTimeImmutable('2026-11-12 10:00:00'));

        // The discussion opens at post 12, the first after what moved
        $this->actingAs($reader)->get('/foro/t/2-larga')->assertInertia(fn (Assert $page) => $page
            ->where('focusNumber', 12)
            ->where('pagination.page', 2));
        // And the lists resume there too
        $this->actingAs($reader)->get('/foro/c/general')->assertInertia(fn (Assert $page) => $page
            ->where('discussions.items', static function (mixed $items) use ($long): bool {
                foreach (is_iterable($items) ? $items : [] as $item) {
                    if (is_array($item) && ($item['id'] ?? null) === $long->id()->value) {
                        return ($item['resumeUrl'] ?? null) === '/foro/t/2-larga/12';
                    }
                }

                return false;
            }));
    }

    public function test_a_reply_after_a_split_takes_the_next_number_and_its_address(): void
    {
        app(ForumModeration::class)->split($this->moderator, $this->discussion->id(), [5], 'Otro asunto', $this->general->id());

        $reply = app(ForumWriter::class)->reply($this->discussion->id(), PostId::generate(), null, self::aBody('Nueva'), new DateTimeImmutable('2026-11-12 10:00:00'), false);

        $this->assertSame(6, $reply->number()?->value);
        $this->get('/foro/t/1-mesa-del-viernes/6')->assertOk();
    }

    /**
     * «Larga» (number 2): 25 posts from 10:00, one a minute.
     */
    private function longDiscussion(): Discussion
    {
        [$long] = $this->aDiscussion($this->general, '2026-11-01 10:00:00', title: 'Larga');
        for ($index = 2; $index <= 25; $index++) {
            $this->aReply($long, sprintf('2026-11-01 10:%02d:00', $index), text: "Mensaje {$index}");
        }

        return $long;
    }

    /**
     * @param  array<string, mixed>  $fields
     * @return TestResponse<Response>
     */
    private function split(array $fields, ?UserModel $as = null): TestResponse
    {
        return $this->actingAs($as ?? $this->moderator)->postJson(route('forum.moderation.discussions.split', ['discussion' => $this->discussion->id()->value]), $fields);
    }

    /**
     * @param  array<string, mixed>  $fields
     * @return TestResponse<Response>
     */
    private function merge(array $fields, ?UserModel $as = null): TestResponse
    {
        return $this->actingAs($as ?? $this->moderator)->postJson(route('forum.moderation.discussions.merge', ['discussion' => $this->discussion->id()->value]), $fields);
    }

    private function mergeTargetUrl(string $typed): string
    {
        return route('forum.moderation.discussions.merge-target', ['discussion' => $this->discussion->id()->value, 'tema' => $typed]);
    }
}
