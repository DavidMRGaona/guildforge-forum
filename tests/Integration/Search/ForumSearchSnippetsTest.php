<?php

declare(strict_types=1);

namespace Modules\Forum\Tests\Integration\Search;

use App\Application\Search\Contracts\WithholdsSearchSnippets;
use App\Application\Search\Services\SearchSourceRegistryInterface;
use App\Infrastructure\Search\Index\NullSearchIndexWriter;
use App\Infrastructure\Search\Index\SearchIndexWriterInterface;
use App\Infrastructure\Search\Persistence\Eloquent\Models\SearchDocumentModel;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Forum\Domain\Entities\Category;
use Modules\Forum\Domain\Entities\Discussion;
use Modules\Forum\Domain\ValueObjects\CategoryAudience;
use Modules\Forum\Infrastructure\Search\DiscussionSearchSource;
use Modules\Forum\Tests\Support\BuildsForumContent;
use Tests\Support\Modules\ModuleTestCase;
use Tests\Support\Time\UsesUtcStorage;

/**
 * A discussion whose opening post moderation has hidden stays listed, but its stored document still holds
 * that post's text until the queued refresh rewrites it: the site search shows it with its title alone.
 */
final class ForumSearchSnippetsTest extends ModuleTestCase
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

    public function test_only_the_discussion_source_withholds_stale_snippets(): void
    {
        $withholding = array_map(
            static fn ($source): string => $source->type(),
            array_values(array_filter(app(SearchSourceRegistryInterface::class)->indexedSources(), static fn ($source): bool => $source instanceof WithholdsSearchSnippets)),
        );

        // A reply's document is its own text: hidden, the whole result goes (ChecksSearchHits)
        $this->assertContains('forum-discussion', $withholding);
        $this->assertNotContains('forum-post', $withholding);
    }

    public function test_a_discussion_with_its_opening_hidden_is_shown_by_its_title_alone_until_its_document_is_refreshed(): void
    {
        [$hidden] = $this->aDiscussion($this->general, title: 'Quedada del sábado');
        [$normal] = $this->aDiscussion($this->general, title: 'Quedada del domingo');
        $this->hideOpeningOf($hidden);

        // The stored document still holds the hidden post's text
        $this->assertStringContainsString('Primer mensaje', (string) SearchDocumentModel::query()->where('source_id', $hidden->id()->value)->value('body'));
        $this->assertSame([$hidden->id()->value], $this->discussions()->staleSearchSnippets([$hidden->id()->value, $normal->id()->value], null));

        $snippets = [];
        $this->get('/buscar?q=quedada')->assertOk()->assertInertia(function (Assert $page) use (&$snippets): Assert {
            foreach ($page->toArray()['props']['results'] as $result) {
                $snippets[$result['title']] = $result['snippet'];
            }

            return $page->where('total', 2);
        });

        $this->assertSame([], $snippets['Quedada del sábado']);
        $this->assertNotSame([], $snippets['Quedada del domingo']);
    }

    public function test_a_discussion_with_its_opening_visible_keeps_its_snippet(): void
    {
        [$discussion] = $this->aDiscussion($this->general);
        $this->aReply($discussion, '2026-11-10 18:10:00');

        $this->assertSame([], $this->discussions()->staleSearchSnippets([$discussion->id()->value], null));
    }

    public function test_ids_that_are_not_uuids_are_left_out_without_a_query(): void
    {
        [$discussion] = $this->aDiscussion($this->general);
        $this->hideOpeningOf($discussion);

        DB::enableQueryLog();
        $this->assertSame([], $this->discussions()->staleSearchSnippets(['no-es-un-uuid', '12'], null));
        $this->assertSame([], DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame([$discussion->id()->value], $this->discussions()->staleSearchSnippets(['no-es-un-uuid', strtoupper($discussion->id()->value)], null));
    }

    public function test_a_whole_page_is_read_in_one_query(): void
    {
        $ids = [];
        for ($i = 0; $i < 6; $i++) {
            [$discussion] = $this->aDiscussion($this->general, title: "Lote {$i}");
            if ($i % 2 === 0) {
                $this->hideOpeningOf($discussion);
            }
            $ids[] = $discussion->id()->value;
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        $stale = $this->discussions()->staleSearchSnippets($ids, null);
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame(1, $queries);
        $this->assertEqualsCanonicalizing([$ids[0], $ids[2], $ids[4]], $stale);
    }

    /**
     * As moderation leaves it, before the queued refresh rewrites the stored document.
     */
    private function hideOpeningOf(Discussion $discussion): void
    {
        DB::table('forum_posts')->where('discussion_id', $discussion->id()->value)->where('number', 1)->update(['status' => 'hidden']);
    }

    private function discussions(): DiscussionSearchSource
    {
        return app(DiscussionSearchSource::class);
    }
}
