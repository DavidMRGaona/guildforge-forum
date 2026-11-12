<?php

declare(strict_types=1);

namespace Modules\Forum\Infrastructure\Queries;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Modules\Forum\Application\Queries\MovedPostRowDTO;
use Modules\Forum\Application\Queries\MovedPostsQueryInterface;
use Modules\Forum\Domain\ValueObjects\DiscussionId;
use Modules\Forum\Infrastructure\Persistence\Eloquent\Repositories\EloquentRedirectRepository;

final readonly class EloquentMovedPostsQuery implements MovedPostsQueryInterface
{
    public function inRange(DiscussionId $from, int $fromNumber, ?int $through): array
    {
        $query = DB::table(EloquentRedirectRepository::POSTS.' as r')
            ->leftJoin('forum_posts as p', 'p.id', '=', 'r.post_id')
            ->where('r.from_discussion_id', $from->value)
            ->where('r.from_number', '>=', $fromNumber);
        if ($through !== null) {
            $query->where('r.from_number', '<=', $through);
        }

        return array_values($query
            ->orderBy('r.from_number')
            ->get(['r.from_number', 'p.discussion_id', 'p.number'])
            ->map(static fn (object $row): MovedPostRowDTO => new MovedPostRowDTO(
                (int) $row->from_number,
                is_string($row->discussion_id ?? null) ? $row->discussion_id : null,
                is_numeric($row->number ?? null) ? (int) $row->number : null,
            ))
            ->all());
    }

    public function resumeAfterMoves(array $numbers): array
    {
        if ($numbers === []) {
            return [];
        }

        // Almost always nothing moved: one look at the redirects first
        $moved = DB::table(EloquentRedirectRepository::POSTS)
            ->where(static function (Builder $query) use ($numbers): void {
                foreach ($numbers as $discussionId => $number) {
                    $query->orWhere(static fn (Builder $pair) => $pair->where('from_discussion_id', $discussionId)->where('from_number', $number));
                }
            })
            ->pluck('from_discussion_id')
            ->all();
        if ($moved === []) {
            return [];
        }

        $resume = [];
        $rows = DB::table('forum_posts')
            ->where(static function (Builder $query) use ($moved, $numbers): void {
                foreach ($moved as $discussionId) {
                    $query->orWhere(static fn (Builder $pair) => $pair->where('discussion_id', $discussionId)->where('number', '>', $numbers[$discussionId]));
                }
            })
            ->groupBy('discussion_id')
            ->selectRaw('discussion_id, MIN(number) as first_number')
            ->get();
        foreach ($rows as $row) {
            $resume[(string) $row->discussion_id] = (int) $row->first_number;
        }

        return $resume;
    }

    public function countInRanges(array $ranges): array
    {
        if ($ranges === []) {
            return [];
        }

        // One range read of the primary key (discussion, number) per discussion, one question
        $rows = DB::table(EloquentRedirectRepository::POSTS)
            ->where(static function (Builder $query) use ($ranges): void {
                foreach ($ranges as $discussionId => $range) {
                    $query->orWhere(static fn (Builder $pair) => $pair
                        ->where('from_discussion_id', $discussionId)
                        ->where('from_number', '>', $range['after'])
                        ->where('from_number', '<=', $range['through']));
                }
            })
            ->groupBy('from_discussion_id')
            ->selectRaw('from_discussion_id, COUNT(*) as moved')
            ->get();

        $counts = [];
        foreach ($rows as $row) {
            $counts[(string) $row->from_discussion_id] = (int) $row->moved;
        }

        return $counts;
    }
}
