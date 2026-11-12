<?php

declare(strict_types=1);

namespace Modules\Forum\Tests\Integration\Search;

use App\Application\Publications\Services\PublicationSourceRegistryInterface;
use App\Application\Search\Contracts\ChecksSearchHits;
use App\Application\Search\Services\SearchSourceRegistryInterface;
use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use App\Infrastructure\Search\Index\NullSearchIndexWriter;
use App\Infrastructure\Search\Index\SearchIndexWriterInterface;
use App\Infrastructure\Search\Persistence\Eloquent\Models\SearchDocumentModel;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Mockery;
use Modules\Forum\Domain\Entities\Category;
use Modules\Forum\Domain\Enums\SystemCategory;
use Modules\Forum\Domain\Repositories\CategoryRepositoryInterface;
use Modules\Forum\Domain\ValueObjects\CategoryAudience;
use Modules\Forum\Domain\ValueObjects\CategoryId;
use Modules\Forum\Domain\ValueObjects\CategorySlug;
use Modules\Forum\Infrastructure\Search\DiscussionSearchSource;
use Modules\Forum\Infrastructure\Search\PostSearchSource;
use Modules\Forum\Tests\Support\BuildsForumContent;
use Modules\Forum\Tests\Support\FakeGameTableSource;
use RuntimeException;
use stdClass;
use Tests\Support\Modules\ModuleTestCase;
use Tests\Support\Time\UsesUtcStorage;

/**
 * The site search asks the forum, when it reads the results, which ones are still visible: the index may
 * be behind a moderation action, a move or a module that has just gone.
 */
final class ForumSearchHitChecksTest extends ModuleTestCase
{
    use BuildsForumContent;
    use UsesUtcStorage;

    protected ?string $moduleName = 'forum';

    protected bool $autoEnableModule = true;

    private Category $general;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useUtcStorage();
        config(['scout.driver' => 'null']);
        $this->app->instance(SearchIndexWriterInterface::class, new NullSearchIndexWriter);
        $this->general = $this->aCategoryFor(CategoryAudience::everyone(), 'general');
    }

    public function test_both_sources_check_their_results(): void
    {
        $checking = array_map(
            static fn ($source): string => $source->type(),
            array_filter(app(SearchSourceRegistryInterface::class)->indexedSources(), static fn ($source): bool => $source instanceof ChecksSearchHits),
        );

        $this->assertContains('forum-discussion', $checking);
        $this->assertContains('forum-post', $checking);
    }

    public function test_a_post_hidden_by_moderation_does_not_show_in_the_site_search_while_its_document_is_old(): void
    {
        [$discussion] = $this->aDiscussion($this->general, title: 'Tema abierto');
        $hidden = $this->aReply($discussion, '2026-11-10 18:10:00', text: 'Fantasma oculto');
        $this->aReply($discussion, '2026-11-10 18:11:00', text: 'Fantasma visible');

        DB::table('forum_posts')->where('id', $hidden->id()->value)->update(['status' => 'hidden']);

        $this->assertTrue(SearchDocumentModel::query()->where('source_id', $hidden->id()->value)->exists());
        $this->get('/buscar?q=fantasma')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('total', 1)
            ->has('results', 1)
            ->where('results.0.snippet.0.text', fn (string $text): bool => str_contains($text, 'visible') || str_contains($text, 'Fantasma')));
        $this->assertSame([], $this->posts()->visibleSearchHits([$hidden->id()->value], null));
    }

    public function test_a_discussion_moved_to_a_category_the_reader_cannot_read_disappears(): void
    {
        $closed = $this->aCategoryFor(CategoryAudience::roles(['7c9e6679-7425-40de-944b-e07fc1f90ae7']), 'directiva', position: 1);
        [$stays] = $this->aDiscussion($this->general, title: 'Rumor que se queda');
        [$moved] = $this->aDiscussion($this->general, title: 'Rumor que se mueve');
        $reply = $this->aReply($moved, '2026-11-10 18:10:00', text: 'Rumor en la respuesta');

        DB::table('forum_discussions')->where('id', $moved->id()->value)->update(['category_id' => $closed->id()->value]);

        $this->get('/buscar?q=rumor')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('total', 1)
            ->has('results', 1)
            ->where('results.0.title', 'Rumor que se queda'));

        $account = $this->anAccount();
        $this->assertSame([$stays->id()->value], $this->discussions()->visibleSearchHits([$stays->id()->value, $moved->id()->value], $account));
        $this->assertSame([], $this->posts()->visibleSearchHits([$reply->id()->value], $account));
    }

    public function test_the_forums_moderators_still_read_what_the_forum_lets_them_read(): void
    {
        $closed = $this->aCategoryFor(CategoryAudience::roles(['7c9e6679-7425-40de-944b-e07fc1f90ae7']), 'directiva', position: 1);
        [$moved] = $this->aDiscussion($this->general, title: 'Rumor que se mueve');
        DB::table('forum_discussions')->where('id', $moved->id()->value)->update(['category_id' => $closed->id()->value]);

        $this->assertSame([$moved->id()->value], $this->discussions()->visibleSearchHits([$moved->id()->value], $this->anAccount('forum:forum.moderate')));
    }

    public function test_a_hidden_deleted_or_unlisted_discussion_disappears(): void
    {
        [$hidden] = $this->aDiscussion($this->general, title: 'Eco oculto');
        [$gone] = $this->aDiscussion($this->general, title: 'Eco borrado');
        [$pending] = $this->aDiscussion($this->general, needsApproval: true, title: 'Eco pendiente');
        [$visible] = $this->aDiscussion($this->general, title: 'Eco visible');
        DB::table('forum_discussions')->where('id', $hidden->id()->value)->update(['is_hidden' => true]);
        DB::table('forum_discussions')->where('id', $gone->id()->value)->delete();

        $ids = [$hidden->id()->value, $gone->id()->value, $pending->id()->value, $visible->id()->value];

        $this->assertSame([$visible->id()->value], $this->discussions()->visibleSearchHits($ids, null));
    }

    public function test_what_a_missing_module_fed_disappears_for_readers(): void
    {
        $registry = app(PublicationSourceRegistryInterface::class);
        $registry->register(FakeGameTableSource::class, 'example');
        $this->asANewProcess();
        $tables = Category::system(CategoryId::generate(), SystemCategory::GameTables, 'Mesas', new CategorySlug('mesas'), 2);
        app(CategoryRepositoryInterface::class)->save($tables);
        [$discussion] = $this->aDiscussion($tables, title: 'Mesa de Pathfinder');
        $reply = $this->aReply($discussion, '2026-11-10 18:10:00', text: 'Llevo Pathfinder');
        $this->get('/buscar?q=pathfinder')->assertInertia(fn (Assert $page) => $page->where('total', 2));

        // Game tables are disabled; the core's search:sync, which drops these documents, has not run yet
        $registry->unregisterModule('example');
        $this->asANewProcess();

        $this->get('/buscar?q=pathfinder')->assertOk()->assertInertia(fn (Assert $page) => $page->where('total', 0)->has('results', 0));
        $this->actingAs($this->anAccount())->get('/buscar?q=pathfinder')->assertInertia(fn (Assert $page) => $page->where('total', 0));
        $this->assertSame([], $this->discussions()->visibleSearchHits([$discussion->id()->value], $this->anAccount()));
        $this->assertSame([], $this->posts()->visibleSearchHits([$reply->id()->value], null));
    }

    public function test_ids_that_are_not_uuids_are_left_out_without_a_query(): void
    {
        [$discussion] = $this->aDiscussion($this->general);

        DB::enableQueryLog();
        $this->assertSame([], $this->discussions()->visibleSearchHits(['no-es-un-uuid', '12'], null));
        $this->assertSame([], $this->posts()->visibleSearchHits(['no-es-un-uuid'], null));
        $this->assertSame([], DB::getQueryLog());

        $this->assertSame([$discussion->id()->value], $this->discussions()->visibleSearchHits(['no-es-un-uuid', strtoupper($discussion->id()->value)], null));
    }

    public function test_something_that_is_not_an_account_reads_nothing(): void
    {
        [$discussion] = $this->aDiscussion($this->general);

        $this->assertSame([], $this->discussions()->visibleSearchHits([$discussion->id()->value], new stdClass));
    }

    public function test_a_whole_batch_costs_the_same_queries_however_many_ids_it_holds(): void
    {
        $account = $this->anAccount();
        $other = $this->aCategoryFor(CategoryAudience::signedIn(), 'cuentas', position: 1);
        $few = $this->batch($other, 1);
        $many = $this->batch($other, 12);

        $this->assertSame($this->queriesOf($few, $account), $this->queriesOf($many, $account));
        $this->assertSame(count($many['discussions']), count($this->discussions()->visibleSearchHits($many['discussions'], $account)));
        $this->assertSame(count($many['posts']), count($this->posts()->visibleSearchHits($many['posts'], $account)));
        // One query for the rows of each type, one for the categories: nothing per id
        $this->assertLessThanOrEqual(4, $this->queriesOf($many, $account));
    }

    public function test_a_check_that_fails_closes_the_result_of_that_type(): void
    {
        [$discussion] = $this->aDiscussion($this->general, title: 'Tema visible hoy');
        $this->get('/buscar?q=visible')->assertInertia(fn (Assert $page) => $page->where('total', 1));

        $categories = Mockery::mock(CategoryRepositoryInterface::class)->shouldIgnoreMissing();
        $categories->shouldReceive('all')->andThrow(new RuntimeException('categories unavailable'));
        $this->app->instance(CategoryRepositoryInterface::class, $categories);
        $this->app->forgetScopedInstances();

        $this->get('/buscar?q=visible')->assertOk()->assertInertia(fn (Assert $page) => $page->where('total', 0)->has('results', 0));
        $this->assertTrue(SearchDocumentModel::query()->where('source_id', $discussion->id()->value)->exists());
    }

    public function test_the_search_inside_the_forum_still_finds_what_accounts_may_read(): void
    {
        $account = $this->anAccount();
        $accounts = $this->aCategoryFor(CategoryAudience::signedIn(), 'cuentas', position: 1);
        $this->aDiscussion($accounts, title: 'Tema de cuentas con luz');

        $this->get('/foro/buscar?q=luz')->assertInertia(fn (Assert $page) => $page->where('results.total', 0));
        $this->actingAs($account)->get('/foro/buscar?q=luz')->assertInertia(fn (Assert $page) => $page->where('results.total', 1));
    }

    /**
     * @return array{discussions: non-empty-list<string>, posts: non-empty-list<string>}
     */
    private function batch(Category $category, int $size): array
    {
        $discussions = [];
        $posts = [];
        for ($i = 0; $i < max(1, $size); $i++) {
            [$discussion] = $this->aDiscussion($category, title: "Lote {$i}");
            $discussions[] = $discussion->id()->value;
            $posts[] = $this->aReply($discussion, '2026-11-10 18:10:00')->id()->value;
        }

        return ['discussions' => $discussions, 'posts' => $posts];
    }

    /**
     * @param  array{discussions: non-empty-list<string>, posts: non-empty-list<string>}  $batch
     */
    private function queriesOf(array $batch, UserModel $reader): int
    {
        $this->asANewProcess();
        // The reader's roles are read once and kept (and cached for an hour): leave that out of the count
        $this->discussions()->visibleSearchHits($batch['discussions'], $reader);
        $this->asANewProcess();
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->discussions()->visibleSearchHits($batch['discussions'], $reader);
        $this->posts()->visibleSearchHits($batch['posts'], $reader);
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }

    /**
     * Enabling or disabling a module takes effect in the next process: here the requests share one application.
     */
    private function asANewProcess(): void
    {
        $this->app->forgetScopedInstances();
        foreach (app(Router::class)->getRoutes()->getRoutes() as $route) {
            $route->controller = null;
        }
    }

    private function discussions(): DiscussionSearchSource
    {
        return app(DiscussionSearchSource::class);
    }

    private function posts(): PostSearchSource
    {
        return app(PostSearchSource::class);
    }
}
