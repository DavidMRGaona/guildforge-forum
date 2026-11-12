<?php

declare(strict_types=1);

namespace Modules\Forum\Infrastructure\Queries;

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
}
