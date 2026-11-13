<?php

declare(strict_types=1);

namespace Modules\Forum\Infrastructure\Search;

use App\Application\Search\Contracts\ChecksSearchHits;
use App\Application\Search\Contracts\IndexedSearchSourceInterface;
use App\Application\Search\Contracts\WithholdsSearchSnippets;
use App\Application\Search\DTOs\SearchDocumentDTO;
use Illuminate\Support\Str;
use Modules\Forum\Infrastructure\Persistence\Eloquent\Models\DiscussionModel;

/**
 * The discussions readers can find: the title and the opening message. A discussion whose opening
 * moderation has just hidden stays listed, shown by its title alone until its document is refreshed;
 * its meta (the category's name) holds nothing that can be hidden.
 */
final readonly class DiscussionSearchSource implements ChecksSearchHits, IndexedSearchSourceInterface, WithholdsSearchSnippets
{
    public const string TYPE = 'forum-discussion';

    public function __construct(
        private ForumSearchDocuments $documents,
        private ForumSearchHitChecks $checks,
    ) {}

    public function type(): string
    {
        return self::TYPE;
    }

    public function label(): string
    {
        return __('forum::messages.search.discussions.label');
    }

    public function itemLabel(): string
    {
        return __('forum::messages.search.discussions.item');
    }

    public function pastLabel(): ?string
    {
        return null;
    }

    public function document(string $id): ?SearchDocumentDTO
    {
        // Keys are uuids: anything else matches no row, and PostgreSQL rejects it in the query
        if (! Str::isUuid($id)) {
            return null;
        }

        $discussion = ForumSearchDocuments::discussions()->whereKey(strtolower($id))->first();

        return $discussion instanceof DiscussionModel ? ($this->documents->discussionDocuments([$discussion])[0] ?? null) : null;
    }

    /**
     * @param  non-empty-list<string>  $ids
     * @return list<string>
     */
    public function visibleSearchHits(array $ids, ?object $user): array
    {
        return $this->checks->discussions($ids, $user);
    }

    /**
     * @param  non-empty-list<string>  $ids
     * @return list<string>
     */
    public function staleSearchSnippets(array $ids, ?object $user): array
    {
        // Keys are uuids: anything else matches no row, and PostgreSQL rejects it in the query
        $uuids = array_values(array_unique(array_map(strtolower(...), array_filter($ids, Str::isUuid(...)))));

        return ForumSearchDocuments::withoutVisibleOpening($uuids);
    }

    /**
     * @return iterable<SearchDocumentDTO>
     */
    public function documents(): iterable
    {
        $after = null;

        // By key in chunks, the openings of each chunk read in one query
        while (true) {
            // No bound on the first chunk: an empty string is not a uuid for PostgreSQL
            $chunk = ForumSearchDocuments::discussions()->when($after !== null, static fn ($query) => $query->where('id', '>', $after))->orderBy('id')->limit(200)->get();
            $last = $chunk->last();
            if (! $last instanceof DiscussionModel) {
                return;
            }

            yield from $this->documents->discussionDocuments($chunk);
            $after = $last->id;
        }
    }
}
