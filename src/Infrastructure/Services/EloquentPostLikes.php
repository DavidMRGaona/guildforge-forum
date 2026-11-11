<?php

declare(strict_types=1);

namespace Modules\Forum\Infrastructure\Services;

use App\Application\Services\TimezoneServiceInterface;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Modules\Forum\Application\Contracts\PostLikesInterface;
use Modules\Forum\Application\DTOs\PostLikesDTO;
use Modules\Forum\Domain\ValueObjects\PostId;

/**
 * Likes in `forum_post_likes`. Adding one that exists is ignored, so a double click never
 * fails nor counts twice.
 */
final readonly class EloquentPostLikes implements PostLikesInterface
{
    public const string TABLE = 'forum_post_likes';

    public function __construct(
        private TimezoneServiceInterface $timezone,
    ) {}

    public function add(PostId $postId, string $accountId, DateTimeImmutable $at): bool
    {
        return DB::table(self::TABLE)->insertOrIgnore([
            'post_id' => $postId->value,
            'user_id' => strtolower($accountId),
            'created_at' => $this->timezone->toStorage($at)->format('Y-m-d H:i:s'),
        ]) > 0;
    }

    public function remove(PostId $postId, string $accountId): bool
    {
        return DB::table(self::TABLE)
            ->where('post_id', $postId->value)
            ->where('user_id', strtolower($accountId))
            ->delete() > 0;
    }

    public function of(array $postIds, ?string $viewerId, int $names): array
    {
        $ids = array_values(array_unique(array_map(static fn (PostId $id): string => $id->value, $postIds)));
        if ($ids === []) {
            return [];
        }

        $counts = DB::table(self::TABLE)
            ->whereIn('post_id', $ids)
            ->groupBy('post_id')
            ->pluck(DB::raw('COUNT(*) as total'), 'post_id');

        $liked = $viewerId === null ? [] : array_flip(array_map(
            static fn (mixed $id): string => strtolower(is_scalar($id) ? (string) $id : ''),
            DB::table(self::TABLE)->whereIn('post_id', $ids)->where('user_id', strtolower($viewerId))->pluck('post_id')->all(),
        ));

        $latest = [];
        if ($names > 0) {
            // The newest likes of each post, numbered per post
            $ranked = DB::table(self::TABLE)
                ->whereIn('post_id', $ids)
                ->select(['post_id', 'user_id', 'created_at'])
                ->selectRaw('ROW_NUMBER() OVER (PARTITION BY post_id ORDER BY created_at DESC, user_id) AS position');

            $rows = DB::query()->fromSub($ranked, 'ranked')
                ->where('position', '<=', $names)
                ->orderBy('post_id')
                ->orderBy('position')
                ->get(['post_id', 'user_id']);

            foreach ($rows as $row) {
                $latest[strtolower((string) $row->post_id)][] = strtolower((string) $row->user_id);
            }
        }

        $likes = [];
        foreach ($counts as $postId => $count) {
            $key = strtolower((string) $postId);
            $likes[$key] = new PostLikesDTO((int) $count, isset($liked[$key]), $latest[$key] ?? []);
        }

        return $likes;
    }
}
