<?php

declare(strict_types=1);

namespace Modules\Forum\Infrastructure\Queries;

use App\Application\Services\TimezoneServiceInterface;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;
use Modules\Forum\Application\Queries\DiscussionCursor;
use Modules\Forum\Application\Queries\DiscussionListCriteria;
use Modules\Forum\Application\Queries\DiscussionListPageDTO;
use Modules\Forum\Application\Queries\DiscussionListQueryInterface;
use Modules\Forum\Application\Queries\DiscussionRowDTO;
use Modules\Forum\Domain\Enums\DiscussionStatus;
use Modules\Forum\Domain\ValueObjects\DiscussionTopic;
use stdClass;
use Throwable;

/**
 * Discussion lists read straight from the tables, newest activity first, in pages that follow a
 * cursor on (last activity, id). The last activity is compared as the database stores it, so no
 * zone conversion can move a discussion between pages.
 */
final readonly class EloquentDiscussionListQuery implements DiscussionListQueryInterface
{
    public function __construct(
        private TimezoneServiceInterface $timezone,
    ) {}

    public function page(DiscussionListCriteria $criteria): DiscussionListPageDTO
    {
        if ($criteria->categoryIds === []) {
            return new DiscussionListPageDTO([], [], null);
        }

        $pinned = [];
        if ($criteria->pinnedFirst && ! $criteria->after instanceof DiscussionCursor) {
            $pinned = $this->rows($this->base($criteria)->where('d.is_pinned', true)->limit(DiscussionListCriteria::MAX_PINNED)->get());
        }

        $query = $this->base($criteria);
        if ($criteria->pinnedFirst) {
            $query->where('d.is_pinned', false);
        }

        $after = $criteria->after;
        if ($after instanceof DiscussionCursor) {
            $query->where(static function (Builder $where) use ($after): void {
                $where->where('d.last_activity_at', '<', $after->lastActivity)
                    ->orWhere(static function (Builder $tie) use ($after): void {
                        $tie->where('d.last_activity_at', '=', $after->lastActivity)->where('d.id', '<', $after->id);
                    });
            });
        }

        // One more than the page, to know whether another follows
        $fetched = $query->limit($criteria->limit + 1)->get();
        $more = $fetched->count() > $criteria->limit;
        $page = $fetched->take($criteria->limit);

        $next = null;
        $last = $page->last();
        if ($more && $last instanceof stdClass) {
            $next = new DiscussionCursor((string) $last->last_activity_at, (string) $last->id);
        }

        return new DiscussionListPageDTO($pinned, $this->rows($page), $next);
    }

    public function topicsIn(array $categoryIds): array
    {
        if ($categoryIds === []) {
            return [];
        }

        $rows = DB::table('forum_discussion_topics as t')
            ->join('forum_discussions as d', 'd.id', '=', 't.discussion_id')
            ->whereIn('d.category_id', $categoryIds)
            ->where('d.is_hidden', false)
            ->where('t.key', 'like', DiscussionTopic::GAME_SYSTEM_PREFIX.'%')
            ->select(['t.key', DB::raw('MAX(t.label) as label')])
            ->groupBy('t.key')
            ->get();

        $topics = [];
        foreach ($rows as $row) {
            try {
                $topics[] = new DiscussionTopic((string) $row->key, (string) $row->label);
            } catch (Throwable) {
                // A tag that cannot be read is left out of the filter
            }
        }

        usort($topics, static fn (DiscussionTopic $a, DiscussionTopic $b): int => strnatcasecmp($a->label, $b->label));

        return $topics;
    }

    private function base(DiscussionListCriteria $criteria): Builder
    {
        $query = DB::table('forum_discussions as d')
            ->leftJoin('forum_posts as p', 'p.id', '=', 'd.last_post_id')
            ->whereIn('d.category_id', $criteria->categoryIds)
            // A discussion with no visible post (its first awaits approval) is not listed yet
            ->where('d.post_count', '>', 0)
            ->whereNotNull('d.number')
            ->orderByDesc('d.last_activity_at')
            ->orderByDesc('d.id')
            ->select([
                'd.id', 'd.number', 'd.title', 'd.category_id', 'd.author_id', 'd.status', 'd.is_pinned', 'd.is_hidden',
                'd.post_count', 'd.last_activity_at', 'd.created_at', 'p.number as last_post_number', 'p.author_id as last_post_author_id',
            ]);

        $moderated = $criteria->moderatedCategoryIds;
        $query->where(static function (Builder $where) use ($moderated): void {
            $where->where('d.is_hidden', false);
            if ($moderated !== []) {
                $where->orWhereIn('d.category_id', $moderated);
            }
        });

        if ($criteria->topicKey !== null) {
            $topicKey = $criteria->topicKey;
            $query->whereExists(static function (Builder $exists) use ($topicKey): void {
                $exists->selectRaw('1')
                    ->from('forum_discussion_topics as t')
                    ->whereColumn('t.discussion_id', 'd.id')
                    ->where('t.key', $topicKey);
            });
        }

        if ($criteria->readerId !== null) {
            $readerId = $criteria->readerId;
            $query->leftJoin('forum_discussion_reads as r', static function (JoinClause $join) use ($readerId): void {
                $join->on('r.discussion_id', '=', 'd.id')->where('r.user_id', '=', $readerId);
            })->leftJoin('forum_category_reads as cr', static function (JoinClause $join) use ($readerId): void {
                $join->on('cr.category_id', '=', 'd.category_id')->where('cr.user_id', '=', $readerId);
            })->addSelect(['r.last_read_number', 'cr.marked_read_at']);

            // Unread: posts past the reader's mark, published after they marked the category read
            if ($criteria->onlyUnread) {
                $query->whereNotNull('p.number')
                    ->where(static function (Builder $where): void {
                        $where->whereNull('r.last_read_number')->orWhereColumn('r.last_read_number', '<', 'p.number');
                    })
                    ->where(static function (Builder $where): void {
                        $where->whereNull('cr.marked_read_at')->orWhereColumn('d.last_activity_at', '>', 'cr.marked_read_at');
                    });
            }
        }

        return $query;
    }

    /**
     * @param  iterable<stdClass>  $rows
     * @return list<DiscussionRowDTO>
     */
    private function rows(iterable $rows): array
    {
        $rows = is_array($rows) ? $rows : iterator_to_array($rows, false);
        $topics = $this->topicsOf(array_values(array_map(static fn (stdClass $row): string => (string) $row->id, $rows)));

        return array_values(array_map(fn (stdClass $row): DiscussionRowDTO => new DiscussionRowDTO(
            id: strtolower((string) $row->id),
            number: (int) $row->number,
            title: (string) $row->title,
            categoryId: strtolower((string) $row->category_id),
            authorId: $row->author_id === null ? null : strtolower((string) $row->author_id),
            status: DiscussionStatus::tryFrom((string) $row->status) ?? DiscussionStatus::Closed,
            pinned: (bool) $row->is_pinned,
            hidden: (bool) $row->is_hidden,
            postCount: (int) $row->post_count,
            lastPostNumber: $row->last_post_number === null ? null : (int) $row->last_post_number,
            lastPostAuthorId: $row->last_post_author_id === null ? null : strtolower((string) $row->last_post_author_id),
            lastActivityAt: $this->instant($row->last_activity_at ?? $row->created_at),
            createdAt: $this->instant($row->created_at),
            topics: $topics[strtolower((string) $row->id)] ?? [],
            lastReadNumber: $this->readThrough($row),
        ), $rows));
    }

    /**
     * How far the reader read the row: its whole visible length once the category was marked
     * read after its last activity, else their own mark. Null without a reader.
     */
    private function readThrough(stdClass $row): ?int
    {
        if (! property_exists($row, 'last_read_number')) {
            return null;
        }

        $own = (int) ($row->last_read_number ?? 0);
        $last = $row->last_post_number === null ? 0 : (int) $row->last_post_number;

        if ($row->marked_read_at !== null && $row->last_activity_at !== null
            && $this->instant($row->marked_read_at) >= $this->instant($row->last_activity_at)) {
            return max($own, $last);
        }

        return $own;
    }

    /**
     * @param  list<string>  $discussionIds
     * @return array<string, list<DiscussionTopic>>
     */
    private function topicsOf(array $discussionIds): array
    {
        if ($discussionIds === []) {
            return [];
        }

        $topics = [];
        $rows = DB::table('forum_discussion_topics')->whereIn('discussion_id', $discussionIds)->orderBy('label')->get(['discussion_id', 'key', 'label']);
        foreach ($rows as $row) {
            try {
                $topics[strtolower((string) $row->discussion_id)][] = new DiscussionTopic((string) $row->key, (string) $row->label);
            } catch (Throwable) {
                // A tag that cannot be read is not shown
            }
        }

        return $topics;
    }

    /**
     * An instant as the database stores it, read in the storage zone.
     */
    private function instant(mixed $value): DateTimeImmutable
    {
        return CarbonImmutable::parse((string) $value, $this->timezone->storageTimezone())->toDateTimeImmutable();
    }
}
