<?php

declare(strict_types=1);

namespace Modules\Forum\Tests\Feature\Http;

use App\Application\Search\DTOs\SiteSearchQueryDTO;
use App\Application\Search\DTOs\SiteSearchResultDTO;
use App\Application\Search\Services\SiteSearchInterface;
use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use App\Infrastructure\Search\Index\NullSearchIndexWriter;
use App\Infrastructure\Search\Index\SearchIndexWriterInterface;
use App\Infrastructure\Search\Persistence\Eloquent\Models\SearchDocumentModel;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Forum\Application\Services\ForumModeration;
use Modules\Forum\Domain\Entities\Category;
use Modules\Forum\Domain\ValueObjects\CategoryAudience;
use Modules\Forum\Tests\Support\BuildsForumContent;
use Tests\Support\Modules\ModuleTestCase;
use Tests\Support\Time\UsesUtcStorage;

/**
 * The search inside /foro: the same engine as the site search, limited to the forum, with a category
 * filter. Whatever the index says, a hit is shown only if the tables still let this reader read it.
 */
final class ForumSearchPageTest extends ModuleTestCase
{
    use BuildsForumContent;
    use UsesUtcStorage;

    protected ?string $moduleName = 'forum';

    protected bool $autoEnableModule = true;

    private Category $general;

    private Category $rules;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useUtcStorage();
        // The database search, and only the stored documents
        config(['scout.driver' => 'null']);
        $this->app->instance(SearchIndexWriterInterface::class, new NullSearchIndexWriter);
        $this->general = $this->aCategoryFor(CategoryAudience::everyone(), 'general', position: 0);
        $this->rules = $this->aCategoryFor(CategoryAudience::everyone(), 'normas', position: 1);
    }

    public function test_it_finds_discussions_and_replies_with_their_category_and_address(): void
    {
        [$discussion] = $this->aDiscussion($this->general, title: 'Partida de Pathfinder');
        $this->aReply($discussion, '2026-11-10 18:10:00', text: 'Yo llevo los dados de Pathfinder');
        $this->aDiscussion($this->rules, title: 'Normas del foro');

        $this->get('/foro/buscar?q=pathfinder')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Forum/Search', false)
                ->where('query', 'pathfinder')
                ->where('failed', false)
                ->where('results.total', 2)
                ->has('results.items', 2)
                ->where('results.items', function (mixed $items): bool {
                    $byUrl = [];
                    foreach (is_iterable($items) ? $items : [] as $item) {
                        $byUrl[$item['url']] = $item;
                    }
                    $discussion = $byUrl['/foro/t/1-partida-de-pathfinder'] ?? [];
                    $reply = $byUrl['/foro/t/1-partida-de-pathfinder/2'] ?? [];

                    return ($discussion['kind'] ?? null) === 'discussion'
                        && $discussion['categoryName'] === 'General'
                        && $discussion['categoryUrl'] === '/foro/c/general'
                        && ($reply['kind'] ?? null) === 'post'
                        && $reply['postNumber'] === 2
                        && $reply['snippet'][0]['text'] !== '';
                })
                ->has('categories', 2));
    }

    public function test_it_filters_by_category_including_its_subcategories(): void
    {
        $child = $this->aCategoryFor(CategoryAudience::everyone(), 'rol', parent: $this->general);
        $this->aDiscussion($this->general, title: 'Dados en general');
        $this->aDiscussion($child, title: 'Dados de rol');
        $this->aDiscussion($this->rules, title: 'Dados y normas');

        $this->get('/foro/buscar?q=dados&categoria=general')
            ->assertInertia(fn (Assert $page) => $page
                ->where('category', 'general')
                ->where('results.total', 2)
                ->where('results.items.0.categoryName', fn (string $name): bool => in_array($name, ['General', 'Rol'], true)));

        $this->get('/foro/buscar?q=dados&categoria=rol')
            ->assertInertia(fn (Assert $page) => $page->where('results.total', 1)->where('results.items.0.title', 'Dados de rol'));

        $this->get('/foro/buscar?q=dados&categoria=no-existe')
            ->assertInertia(fn (Assert $page) => $page->where('category', null)->where('results.total', 3));
    }

    public function test_guests_and_accounts_find_only_what_their_audience_reads(): void
    {
        $signedIn = $this->aCategoryFor(CategoryAudience::signedIn(), 'cuentas', position: 2);
        $roles = $this->aCategoryFor(CategoryAudience::roles(['7c9e6679-7425-40de-944b-e07fc1f90ae7']), 'directiva', position: 3);
        $this->aDiscussion($this->general, title: 'Tema abierto con luz');
        $this->aDiscussion($signedIn, title: 'Tema de cuentas con luz');
        $this->aDiscussion($roles, title: 'Tema de directiva con luz');

        $this->get('/foro/buscar?q=luz')->assertInertia(fn (Assert $page) => $page->where('results.total', 1));

        $this->actingAs($this->anAccount())->get('/foro/buscar?q=luz')
            ->assertInertia(fn (Assert $page) => $page->where('results.total', 2)->has('categories', 3));
    }

    public function test_a_stale_index_never_shows_what_the_tables_no_longer_allow(): void
    {
        $moderator = $this->anAccount('forum:forum.moderate');
        [$hiddenDiscussion] = $this->aDiscussion($this->general, title: 'Fantasma oculto');
        [$discussion] = $this->aDiscussion($this->general, title: 'Tema visible');
        $hiddenReply = $this->aReply($discussion, '2026-11-10 18:10:00', text: 'Fantasma de respuesta oculta');
        $pendingReply = $this->aReply($discussion, '2026-11-10 18:11:00', needsApproval: true, text: 'Fantasma pendiente');
        $deleted = $this->aReply($discussion, '2026-11-10 18:12:00', text: 'Fantasma borrado');
        $space = $this->aCategoryFor(CategoryAudience::everyone(), 'espacio', position: 4);
        [$moved] = $this->aDiscussion($space, title: 'Fantasma de espacio');

        // The documents exist and are public; the tables then change without the index hearing of it
        $this->assertGreaterThanOrEqual(4, SearchDocumentModel::query()->where('title', 'like', '%antasma%')->orWhere('body', 'like', '%antasma%')->count());
        DB::table('forum_discussions')->where('id', $hiddenDiscussion->id()->value)->update(['is_hidden' => true]);
        DB::table('forum_posts')->where('id', $hiddenReply->id()->value)->update(['status' => 'hidden']);
        DB::table('forum_posts')->where('id', $pendingReply->id()->value)->update(['status' => 'pending']);
        DB::table('forum_posts')->where('id', $deleted->id()->value)->delete();
        // A category that now only some roles read, and a discussion deleted without a trace in the index
        DB::table('forum_categories')->where('id', $space->id()->value)->update(['view_rule' => json_encode(['kind' => 'roles', 'role_ids' => ['7c9e6679-7425-40de-944b-e07fc1f90ae7']])]);
        $gone = $this->aDiscussion($this->general, title: 'Fantasma borrado del todo')[0];
        DB::table('forum_discussions')->where('id', $gone->id()->value)->delete();

        $this->assertTrue(SearchDocumentModel::query()->where('source_id', $moved->id()->value)->exists());

        foreach ([null, $this->anAccount()] as $reader) {
            $request = $reader instanceof UserModel ? $this->actingAs($reader) : $this;
            $request->get('/foro/buscar?q=fantasma')
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page->where('results.total', 0)->has('results.items', 0));
        }

        // Whoever moderates the whole forum still reads the category, so only that one is left for them
        $this->actingAs($moderator)->get('/foro/buscar?q=fantasma')
            ->assertInertia(fn (Assert $page) => $page->where('results.total', 1)->where('results.items.0.title', 'Fantasma de espacio'));
    }

    public function test_a_discussion_whose_opening_message_was_hidden_is_found_by_its_title_only_and_never_quotes_the_old_text(): void
    {
        [$discussion, $opening] = $this->aDiscussion($this->general, title: 'Tema con apertura retirada');
        $this->assertStringContainsString('Primer mensaje', (string) SearchDocumentModel::query()->where('source_id', $discussion->id()->value)->value('body'));

        // Hidden without the index hearing of it
        DB::table('forum_posts')->where('id', $opening->id()->value)->update(['status' => 'hidden']);

        $this->get('/foro/buscar?q=retirada')->assertInertia(fn (Assert $page) => $page
            ->where('results.total', 1)
            ->where('results.items.0.title', 'Tema con apertura retirada')
            ->where('results.items.0.snippet', []));
        // The hidden text must not be findable through the stale index either
        $this->get('/foro/buscar?q=primer')->assertInertia(fn (Assert $page) => $page->where('results.total', 0));
        $this->get('/foro/buscar?q=retirada+primer')->assertInertia(fn (Assert $page) => $page->where('results.total', 0));
    }

    public function test_an_empty_or_too_short_query_searches_nothing(): void
    {
        $this->aDiscussion($this->general, title: 'Algo');

        $this->get('/foro/buscar')->assertOk()->assertInertia(fn (Assert $page) => $page->where('query', '')->where('results', null));
        $this->get('/foro/buscar?q=%20%20')->assertOk()->assertInertia(fn (Assert $page) => $page->where('results', null));
        $this->get('/foro/buscar?q[]=x')->assertOk()->assertInertia(fn (Assert $page) => $page->where('query', ''));
    }

    public function test_results_come_in_pages(): void
    {
        for ($n = 1; $n <= 12; $n++) {
            $this->aDiscussion($this->general, sprintf('2026-11-10 10:%02d:00', $n), title: "Mesa número {$n}");
        }

        $this->get('/foro/buscar?q=mesa')->assertInertia(fn (Assert $page) => $page
            ->where('results.total', 12)
            ->has('results.items', 10)
            ->where('results.page', 1)
            ->where('results.prevUrl', null)
            ->where('results.nextUrl', '/foro/buscar?q=mesa&pagina=2'));

        $this->get('/foro/buscar?q=mesa&pagina=2')->assertInertia(fn (Assert $page) => $page
            ->has('results.items', 2)
            ->where('results.nextUrl', null)
            ->where('results.prevUrl', '/foro/buscar?q=mesa'));

        $this->get('/foro/buscar?q=mesa&pagina=99')->assertInertia(fn (Assert $page) => $page->has('results.items', 2)->where('results.page', 2));
    }

    public function test_a_failing_engine_answers_a_page_never_a_server_error(): void
    {
        $this->app->bind(SiteSearchInterface::class, static fn () => new class implements SiteSearchInterface
        {
            public function search(SiteSearchQueryDTO $query): SiteSearchResultDTO
            {
                throw new \RuntimeException('engine down');
            }
        });

        $this->get('/foro/buscar?q=algo')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('failed', true)->where('results', null));
    }

    public function test_the_search_is_not_cached_and_hidden_or_moved_content_leaves_it_at_once(): void
    {
        $moderator = $this->anAccount('forum:forum.moderate');
        [$discussion] = $this->aDiscussion($this->general, title: 'Torneo de verano');
        $reply = $this->aReply($discussion, '2026-11-10 18:10:00', text: 'Inscripciones del torneo');
        $this->get('/foro/buscar?q=torneo')->assertInertia(fn (Assert $page) => $page->where('results.total', 2));

        app(ForumModeration::class)->hidePost($moderator, $reply->id());
        $this->get('/foro/buscar?q=torneo')->assertInertia(fn (Assert $page) => $page->where('results.total', 1));

        app(ForumModeration::class)->hide($moderator, $discussion->id());
        $this->get('/foro/buscar?q=torneo')->assertInertia(fn (Assert $page) => $page->where('results.total', 0));
    }
}
