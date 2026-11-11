<?php

declare(strict_types=1);

namespace Modules\Forum\Infrastructure\Queries;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Forum\Application\Queries\ModerationQueueQueryInterface;
use Modules\Forum\Application\Queries\PendingPostDTO;
use Modules\Forum\Application\Queries\ReportedPostDTO;
use Modules\Forum\Domain\Enums\PostStatus;
use Modules\Forum\Infrastructure\Persistence\Eloquent\Models\PostModel;
use Modules\Forum\Infrastructure\Persistence\Eloquent\Models\ReportModel;

/**
 * Reads what awaits moderation through the models, so instants come in the storage zone as
 * every other read.
 */
final class EloquentModerationQueueQuery implements ModerationQueueQueryInterface
{
    private const int EXCERPT = 240;

    private const int NOTES = 3;

    public function reportedPosts(?array $categoryIds, int $limit): array
    {
        if ($categoryIds === []) {
            return [];
        }

        $open = ReportModel::query()
            ->whereNull('resolution')
            ->whereHas('post.discussion', static function ($query) use ($categoryIds): void {
                $query->whereNotNull('number');
                if ($categoryIds !== null) {
                    $query->whereIn('category_id', $categoryIds);
                }
            })
            ->select('post_id', DB::raw('MIN(created_at) as first_at'), DB::raw('COUNT(*) as total'))
            ->groupBy('post_id')
            ->orderBy('first_at')
            ->limit($limit)
            ->get();

        $postIds = $open->pluck('post_id')->all();
        $posts = PostModel::query()->with('discussion')->whereKey($postIds)->get()->keyBy('id');
        $reports = ReportModel::query()->whereNull('resolution')->whereIn('post_id', $postIds)->orderBy('created_at')->get()->groupBy('post_id');

        $rows = [];
        foreach ($open as $row) {
            $post = $posts->get($row->post_id);
            $discussion = $post?->discussion;
            if (! $post instanceof PostModel || $discussion === null || $discussion->number === null) {
                continue;
            }

            $own = $reports->get($row->post_id, collect());
            $first = $own->first();
            $rows[] = new ReportedPostDTO(
                postId: $post->id,
                postNumber: $post->number,
                status: $post->status,
                excerpt: Str::limit(trim($post->plain_text), self::EXCERPT),
                authorId: $post->author_id,
                discussionId: $discussion->id,
                discussionNumber: $discussion->number,
                discussionTitle: $discussion->title,
                categoryId: $discussion->category_id,
                reports: $own->count(),
                reasons: array_values(array_unique($own->pluck('reason')->map(static fn (mixed $reason): string => (string) $reason)->all())),
                notes: array_slice(array_values(array_filter($own->pluck('note')->all(), is_string(...))), 0, self::NOTES),
                firstReportedAt: $first instanceof ReportModel ? $first->created_at->toDateTimeImmutable() : $post->created_at->toDateTimeImmutable(),
            );
        }

        return $rows;
    }

    public function pendingPosts(?array $categoryIds, int $limit): array
    {
        if ($categoryIds === []) {
            return [];
        }

        $posts = PostModel::query()
            ->with('discussion')
            ->where('status', PostStatus::Pending->value)
            ->whereHas('discussion', static function ($query) use ($categoryIds): void {
                $query->whereNotNull('number');
                if ($categoryIds !== null) {
                    $query->whereIn('category_id', $categoryIds);
                }
            })
            ->orderBy('created_at')
            ->orderBy('id')
            ->limit($limit)
            ->get();

        $rows = [];
        foreach ($posts as $post) {
            $discussion = $post->discussion;
            if ($discussion === null || $discussion->number === null) {
                continue;
            }

            $rows[] = new PendingPostDTO(
                postId: $post->id,
                excerpt: Str::limit(trim($post->plain_text), self::EXCERPT),
                authorId: $post->author_id,
                discussionId: $discussion->id,
                discussionNumber: $discussion->number,
                discussionTitle: $discussion->title,
                categoryId: $discussion->category_id,
                opensDiscussion: $discussion->awaiting_approval,
                writtenAt: $post->created_at->toDateTimeImmutable(),
            );
        }

        return $rows;
    }
}
