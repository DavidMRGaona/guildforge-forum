<?php

declare(strict_types=1);

namespace Modules\Forum\Application\Search;

use App\Application\Search\DTOs\SiteSearchHitDTO;
use App\Application\Search\DTOs\SiteSearchQueryDTO;
use App\Application\Search\Services\SearchAudienceResolverInterface;
use App\Application\Search\Services\SiteSearchInterface;
use Illuminate\Contracts\Auth\Authenticatable;
use Modules\Forum\Application\DTOs\CategoryAccessDTO;
use Modules\Forum\Application\Queries\SearchHitsQueryInterface;
use Modules\Forum\Application\Queries\VisibleSearchHitDTO;
use Modules\Forum\Domain\ValueObjects\CategoryId;
use Throwable;

/**
 * The search inside the forum: the site's engine limited to the forum's two types, then every hit
 * checked against the tables and the categories the reader may read, so an index that is behind (a post
 * hidden a moment ago, a category now closed to this reader) never shows more than the forum allows.
 * It fails closed and never throws: an engine that fails gives an empty, marked page.
 *
 * The engine only knows audiences, so what they cannot express (roles, a campaign's roster) is not in
 * the index and is not found here either; the category pages list it.
 */
final readonly class ForumSearch
{
    public const int PER_PAGE = 10;

    /** How many hits are asked of the engine and checked; a search that matches more says so. */
    public const int FETCH = 100;

    public const int MIN_LENGTH = 2;

    public const int MAX_LENGTH = 200;

    /** The two types of the forum's sources (their classes live in Infrastructure). */
    private const array TYPES = ['forum-discussion', 'forum-post'];

    public function __construct(
        private SiteSearchInterface $engine,
        private SearchAudienceResolverInterface $audiences,
        private SearchHitsQueryInterface $hits,
    ) {}

    /**
     * @param  list<CategoryAccessDTO>  $readable  the categories the reader may read
     * @param  CategoryAccessDTO|null  $within  the category (and its subcategories) to look in; null looks everywhere
     */
    public function search(?Authenticatable $reader, array $readable, string $query, ?CategoryAccessDTO $within, int $page): ForumSearchPageDTO
    {
        try {
            $result = $this->engine->search(new SiteSearchQueryDTO(
                query: $query,
                audiences: $this->audiences->for($reader),
                types: self::TYPES,
                type: null,
                offset: 0,
                limit: self::FETCH,
                user: null,
                includeHelp: false,
            ));

            $found = [];
            foreach ($result->hits as $hit) {
                $address = $this->address($hit->url);
                if ($address !== null) {
                    $found[] = [$hit, $address];
                }
            }

            $visible = $this->hits->visible(array_map(static fn (array $pair): array => $pair[1], $found));
            $scope = $this->scope($readable, $within);

            $hits = [];
            foreach ($found as [$hit, $address]) {
                $live = $visible["{$address[0]}:{$address[1]}"] ?? null;
                if ($live instanceof VisibleSearchHitDTO && isset($scope[$live->categoryId])) {
                    $hits[] = $this->hitOf($hit, $live, $address[1]);
                }
            }
        } catch (Throwable $exception) {
            report($exception);

            return ForumSearchPageDTO::failed();
        }

        $pages = max(1, (int) ceil(count($hits) / self::PER_PAGE));
        $page = min(max(1, $page), $pages);

        return new ForumSearchPageDTO(
            hits: array_slice($hits, ($page - 1) * self::PER_PAGE, self::PER_PAGE),
            total: count($hits),
            page: $page,
            pages: $pages,
            truncated: $result->total > self::FETCH,
            failed: false,
        );
    }

    /**
     * The discussion number and reply number (0 for the discussion) an address names: /foro/t/12-slug[/3].
     *
     * @return array{int, int}|null
     */
    private function address(string $url): ?array
    {
        $path = parse_url($url, PHP_URL_PATH);
        if (! is_string($path) || preg_match('~/t/([1-9][0-9]{0,9})(?:-[a-z0-9-]*)?(?:/([1-9][0-9]{0,9}))?$~', $path, $matches) !== 1) {
            return null;
        }

        return [(int) $matches[1], (int) ($matches[2] ?? 0)];
    }

    /**
     * @param  list<CategoryAccessDTO>  $readable
     * @return array<string, true> ids of the categories to look in
     */
    private function scope(array $readable, ?CategoryAccessDTO $within): array
    {
        $ids = [];
        foreach ($readable as $access) {
            $parent = $access->category->parentId();
            $inside = ! $within instanceof CategoryAccessDTO
                || $access->category->id()->equals($within->category->id())
                || ($parent instanceof CategoryId && $parent->equals($within->category->id()));

            if ($inside) {
                $ids[$access->category->id()->value] = true;
            }
        }

        return $ids;
    }

    private function hitOf(SiteSearchHitDTO $hit, VisibleSearchHitDTO $live, int $reply): ForumSearchHitDTO
    {
        return new ForumSearchHitDTO(
            isReply: $reply > 0,
            title: $live->title,
            url: $hit->url,
            categoryId: $live->categoryId,
            replyNumber: $reply > 0 ? $reply : null,
            // The text comes from the index: never quote an opening message that is no longer visible
            snippet: $reply > 0 || $live->openingVisible ? $hit->snippet : [],
            publishedAt: $hit->publishedAt,
        );
    }
}
