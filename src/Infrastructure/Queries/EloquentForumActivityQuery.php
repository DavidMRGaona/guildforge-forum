<?php

declare(strict_types=1);

namespace Modules\Forum\Infrastructure\Queries;

use App\Application\Services\TimezoneServiceInterface;
use DateTimeImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Modules\Forum\Application\Queries\ForumActivityQueryInterface;
use Modules\Forum\Domain\Enums\FollowLevel;

/**
 * Activity reads on primary and unique keys: the reader's follows (primary key `user_id`,
 * `discussion_id`), their marks (the same key) and the last post of each discussion (its id).
 * A followed category is limited to what moved lately, so a category with years of unread
 * history never turns into a scan on each question.
 */
final readonly class EloquentForumActivityQuery implements ForumActivityQueryInterface
{
    /** Discussions of a followed category count only while they were active this lately */
    public const int CATEGORY_WINDOW_DAYS = 90;

    public function __construct(
        private TimezoneServiceInterface $timezone,
    ) {}

    public function unreadFollowedIds(string $readerId, array $categoryIds, array $moderatedCategoryIds, array $followedCategoryIds, int $limit): array
    {
        if ($categoryIds === [] || $limit < 1) {
            return [];
        }

        $followed = $this->unreadBase($readerId, $categoryIds, $moderatedCategoryIds)
            ->join('forum_discussion_follows as f', static function ($join) use ($readerId): void {
                $join->on('f.discussion_id', '=', 'd.id')
                    ->where('f.user_id', '=', $readerId)
                    ->where('f.level', '=', FollowLevel::Following->value);
            })
            ->limit($limit)
            ->pluck('d.id')
            ->all();

        $inCategories = [];
        $followedCategoryIds = array_values(array_intersect($followedCategoryIds, $categoryIds));
        if ($followedCategoryIds !== []) {
            $inCategories = $this->unreadBase($readerId, $categoryIds, $moderatedCategoryIds)
                ->whereIn('d.category_id', $followedCategoryIds)
                ->where('d.last_activity_at', '>', $this->timezone->toStorage(new DateTimeImmutable('-'.self::CATEGORY_WINDOW_DAYS.' days'))->format('Y-m-d H:i:s'))
                ->limit($limit)
                ->pluck('d.id')
                ->all();
        }

        return array_slice(array_values(array_unique(array_map(
            static fn (mixed $id): string => strtolower((string) $id),
            [...$followed, ...$inCategories],
        ))), 0, $limit);
    }

    public function lastPostNumbers(array $discussionIds, array $categoryIds, array $moderatedCategoryIds): array
    {
        if ($discussionIds === [] || $categoryIds === []) {
            return [];
        }

        $rows = DB::table('forum_discussions as d')
            ->join('forum_posts as p', 'p.id', '=', 'd.last_post_id')
            ->whereIn('d.id', $discussionIds)
            ->whereIn('d.category_id', $categoryIds)
            ->where('d.post_count', '>', 0)
            ->whereNotNull('p.number')
            ->where(static function (Builder $where) use ($moderatedCategoryIds): void {
                $where->where('d.is_hidden', false);
                if ($moderatedCategoryIds !== []) {
                    $where->orWhereIn('d.category_id', $moderatedCategoryIds);
                }
            })
            ->get(['d.id', 'p.number']);

        $numbers = [];
        foreach ($rows as $row) {
            $numbers[strtolower((string) $row->id)] = (int) $row->number;
        }

        return $numbers;
    }

    /**
     * Visible discussions the reader has not read to the end: the same rule the list's «Sin
     * leer» filter applies (their mark, and the category they marked as read).
     *
     * @param  list<string>  $categoryIds
     * @param  list<string>  $moderatedCategoryIds
     */
    private function unreadBase(string $readerId, array $categoryIds, array $moderatedCategoryIds): Builder
    {
        return DB::table('forum_discussions as d')
            ->join('forum_posts as p', 'p.id', '=', 'd.last_post_id')
            ->leftJoin('forum_discussion_reads as r', static function ($join) use ($readerId): void {
                $join->on('r.discussion_id', '=', 'd.id')->where('r.user_id', '=', $readerId);
            })
            ->leftJoin('forum_category_reads as cr', static function ($join) use ($readerId): void {
                $join->on('cr.category_id', '=', 'd.category_id')->where('cr.user_id', '=', $readerId);
            })
            ->whereIn('d.category_id', $categoryIds)
            ->where('d.post_count', '>', 0)
            ->whereNotNull('d.number')
            ->whereNotNull('p.number')
            ->where(static function (Builder $where) use ($moderatedCategoryIds): void {
                $where->where('d.is_hidden', false);
                if ($moderatedCategoryIds !== []) {
                    $where->orWhereIn('d.category_id', $moderatedCategoryIds);
                }
            })
            ->where(static function (Builder $where): void {
                $where->whereNull('r.last_read_number')->orWhereColumn('r.last_read_number', '<', 'p.number');
            })
            ->where(static function (Builder $where): void {
                $where->whereNull('cr.marked_read_at')->orWhereColumn('d.last_activity_at', '>', 'cr.marked_read_at');
            })
            ->orderByDesc('d.last_activity_at');
    }
}
