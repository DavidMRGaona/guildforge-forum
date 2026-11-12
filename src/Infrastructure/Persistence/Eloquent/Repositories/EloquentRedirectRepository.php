<?php

declare(strict_types=1);

namespace Modules\Forum\Infrastructure\Persistence\Eloquent\Repositories;

use App\Application\Services\TimezoneServiceInterface;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Modules\Forum\Domain\Repositories\RedirectRepositoryInterface;
use Modules\Forum\Domain\ValueObjects\DiscussionId;
use Modules\Forum\Domain\ValueObjects\MergedDiscussion;
use Modules\Forum\Domain\ValueObjects\PostId;

/**
 * `forum_post_redirects` and `forum_discussion_redirects`. Instants are written as the database
 * stores them (storage zone).
 */
final readonly class EloquentRedirectRepository implements RedirectRepositoryInterface
{
    public const string POSTS = 'forum_post_redirects';

    public const string DISCUSSIONS = 'forum_discussion_redirects';

    public function __construct(
        private TimezoneServiceInterface $timezone,
    ) {}

    public function recordMovedPosts(DiscussionId $from, array $postsByNumber, DateTimeImmutable $at): void
    {
        if ($postsByNumber === []) {
            return;
        }

        $createdAt = $this->stored($at);
        $rows = [];
        foreach ($postsByNumber as $number => $postId) {
            $rows[] = ['from_discussion_id' => $from->value, 'from_number' => $number, 'post_id' => $postId->value, 'created_at' => $createdAt];
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table(self::POSTS)->insert($chunk);
        }
    }

    public function recordMergedDiscussion(int $fromNumber, DiscussionId $from, DiscussionId $into, DateTimeImmutable $at): void
    {
        // Whatever led to the merged discussion leads further on, so a redirect is one step
        DB::table(self::DISCUSSIONS)->where('to_discussion_id', $from->value)->update(['to_discussion_id' => $into->value]);

        DB::table(self::DISCUSSIONS)->insert([
            'from_number' => $fromNumber,
            'from_discussion_id' => $from->value,
            'to_discussion_id' => $into->value,
            'created_at' => $this->stored($at),
        ]);
    }

    public function highestMovedNumber(DiscussionId $from): ?int
    {
        $highest = DB::table(self::POSTS)->where('from_discussion_id', $from->value)->max('from_number');

        return is_numeric($highest) ? (int) $highest : null;
    }

    public function movedPost(DiscussionId $from, int $number): ?PostId
    {
        $postId = DB::table(self::POSTS)
            ->where('from_discussion_id', $from->value)
            ->where('from_number', $number)
            ->value('post_id');

        return is_string($postId) ? PostId::fromString($postId) : null;
    }

    public function mergedDiscussion(int $number): ?MergedDiscussion
    {
        $row = DB::table(self::DISCUSSIONS)->where('from_number', $number)->first(['from_discussion_id', 'to_discussion_id']);
        if ($row === null || ! is_string($row->from_discussion_id)) {
            return null;
        }

        return new MergedDiscussion(
            DiscussionId::fromString($row->from_discussion_id),
            is_string($row->to_discussion_id) ? DiscussionId::fromString($row->to_discussion_id) : null,
        );
    }

    private function stored(DateTimeImmutable $at): string
    {
        return $this->timezone->toStorage($at)->format('Y-m-d H:i:s');
    }
}
