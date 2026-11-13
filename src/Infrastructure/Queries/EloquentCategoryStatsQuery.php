<?php

declare(strict_types=1);

namespace Modules\Forum\Infrastructure\Queries;

use App\Application\Services\TimezoneServiceInterface;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;
use Modules\Forum\Application\Queries\CategoryStatsDTO;
use Modules\Forum\Application\Queries\CategoryStatsQueryInterface;

/**
 * The counters ForumCountersInterface keeps on each category, with its last post's discussion,
 * in one query.
 */
final readonly class EloquentCategoryStatsQuery implements CategoryStatsQueryInterface
{
    public function __construct(
        private TimezoneServiceInterface $timezone,
    ) {}

    public function statsOf(array $categoryIds): array
    {
        if ($categoryIds === []) {
            return [];
        }

        $rows = DB::table('forum_categories as c')
            ->leftJoin('forum_posts as p', 'p.id', '=', 'c.last_post_id')
            ->leftJoin('forum_discussions as d', 'd.id', '=', 'p.discussion_id')
            // Only a shared character (no owner) is read: a personal one never replaces its author
            ->leftJoin('forum_characters as lc', static function (JoinClause $join): void {
                $join->on('lc.id', '=', 'p.character_id')->whereNull('lc.owner_id');
            })
            ->whereIn('c.id', $categoryIds)
            ->get(['c.id', 'c.discussion_count', 'c.post_count', 'c.last_activity_at', 'd.id as discussion_id', 'd.number as discussion_number', 'd.title', 'd.is_hidden', 'p.number', 'p.author_id', 'lc.name as shared_name']);

        $stats = [];
        foreach ($rows as $row) {
            // The counters leave hidden discussions out; a stale last post never names one
            $shown = $row->discussion_id !== null && $row->discussion_number !== null && ! (bool) $row->is_hidden;

            $stats[strtolower((string) $row->id)] = new CategoryStatsDTO(
                discussionCount: (int) $row->discussion_count,
                postCount: (int) $row->post_count,
                lastActivityAt: $shown && $row->last_activity_at !== null
                    ? CarbonImmutable::parse((string) $row->last_activity_at, $this->timezone->storageTimezone())->toDateTimeImmutable()
                    : null,
                lastDiscussionNumber: $shown ? (int) $row->discussion_number : null,
                lastDiscussionTitle: $shown ? (string) $row->title : null,
                lastPostNumber: $shown && $row->number !== null ? (int) $row->number : null,
                lastPostAuthorId: $shown && $row->author_id !== null && $row->shared_name === null ? strtolower((string) $row->author_id) : null,
                lastPostSharedName: $shown && $row->shared_name !== null ? (string) $row->shared_name : null,
            );
        }

        return $stats;
    }
}
