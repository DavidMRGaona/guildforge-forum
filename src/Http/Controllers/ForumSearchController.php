<?php

declare(strict_types=1);

namespace Modules\Forum\Http\Controllers;

use App\Application\Seo\DTOs\SeoPageDTO;
use App\Application\Seo\Services\SeoMetadataFactoryInterface;
use App\Application\Services\TimezoneServiceInterface;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Forum\Application\Contracts\ForumUrlsInterface;
use Modules\Forum\Application\DTOs\CategoryAccessDTO;
use Modules\Forum\Application\Search\ForumSearch;
use Modules\Forum\Application\Search\ForumSearchHitDTO;
use Modules\Forum\Application\Search\ForumSearchPageDTO;
use Modules\Forum\Application\Services\ForumBrowser;
use Modules\Forum\Http\Presenters\ForumPresenter;

/**
 * /foro/buscar?q=<text>[&categoria=<slug>][&pagina=<n>]: the forum's own search. A category the reader
 * may not read is ignored, as if no category had been chosen.
 */
final class ForumSearchController extends Controller
{
    public function __construct(
        private readonly ForumBrowser $browser,
        private readonly ForumSearch $search,
        private readonly ForumPresenter $presenter,
        private readonly ForumUrlsInterface $urls,
        private readonly TimezoneServiceInterface $timezone,
        private readonly SeoMetadataFactoryInterface $seo,
    ) {}

    public function __invoke(Request $request): Response
    {
        $reader = $request->user();
        $readable = $this->browser->readableCategories($reader);

        $query = $this->text($request->query('q'), ForumSearch::MAX_LENGTH);
        $slug = $this->text($request->query('categoria'), 64);
        $within = $slug === '' ? null : $this->browser->findReadable($readable, $slug);
        $pageNumber = is_string($request->query('pagina')) ? (int) $request->query('pagina') : 1;

        $page = mb_strlen($query) >= ForumSearch::MIN_LENGTH
            ? $this->search->search($reader, $readable, $query, $within, $pageNumber)
            : null;

        $url = static fn (int $number): string => ($all = http_build_query(array_filter([
            'q' => $query,
            'categoria' => $within?->category->slug()->value,
            'pagina' => $number > 1 ? $number : null,
        ]))) === '' ? route('forum.search', [], false) : route('forum.search', [], false).'?'.$all;

        return Inertia::render('Forum/Search', [
            'query' => $query,
            'category' => $within?->category->slug()->value,
            'categories' => $this->presenter->searchBox($readable)['categories'],
            'searchUrl' => route('forum.search', [], false),
            'failed' => $page instanceof ForumSearchPageDTO && $page->failed,
            'results' => $page instanceof ForumSearchPageDTO && ! $page->failed ? [
                'items' => array_map(fn (ForumSearchHitDTO $hit): array => $this->item($hit, $readable), $page->hits),
                'total' => $page->total,
                'page' => $page->page,
                'truncated' => $page->truncated,
                'prevUrl' => $page->page > 1 ? $url($page->page - 1) : null,
                'nextUrl' => $page->page < $page->pages ? $url($page->page + 1) : null,
            ] : null,
            'seo' => $this->seo->forPage(new SeoPageDTO(
                title: __('forum::messages.search.page_title'),
                description: __('forum::messages.search.page_description'),
                noindex: true,
            ))->toArray(),
        ]);
    }

    private function text(mixed $value, int $max): string
    {
        return is_string($value) ? mb_substr(trim($value), 0, $max) : '';
    }

    /**
     * @param  list<CategoryAccessDTO>  $readable
     * @return array<string, mixed>
     */
    private function item(ForumSearchHitDTO $hit, array $readable): array
    {
        $category = null;
        foreach ($readable as $access) {
            if ($access->category->id()->value === $hit->categoryId) {
                $category = $access->category;
            }
        }

        return [
            'kind' => $hit->isReply ? 'post' : 'discussion',
            'title' => $hit->title,
            'url' => $hit->url,
            'categoryName' => $category?->name(),
            'categoryUrl' => $category === null ? null : $this->urls->category($category),
            'postNumber' => $hit->replyNumber,
            'snippet' => $hit->snippet,
            'publishedAt' => $hit->publishedAt instanceof \DateTimeImmutable ? $this->timezone->iso($hit->publishedAt) : null,
            'publishedLabel' => $hit->publishedAt instanceof \DateTimeImmutable ? $this->timezone->format($hit->publishedAt) : null,
        ];
    }
}
