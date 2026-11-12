<?php

declare(strict_types=1);

namespace Modules\Forum\Infrastructure\Services;

use App\Application\Services\TimezoneServiceInterface;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Modules\Forum\Application\Contracts\DiscussionReadsInterface;
use Modules\Forum\Domain\ValueObjects\CategoryId;
use Modules\Forum\Domain\ValueObjects\DiscussionId;

/**
 * The read marks in `forum_discussion_reads` and `forum_category_reads`. A discussion mark only
 * goes up, so two tabs reporting what they show at once never move it back. Instants are
 * written and compared as the database stores them (storage zone).
 */
final readonly class EloquentDiscussionReads implements DiscussionReadsInterface
{
    public const string DISCUSSIONS = 'forum_discussion_reads';

    public const string CATEGORIES = 'forum_category_reads';

    public function __construct(
        private TimezoneServiceInterface $timezone,
    ) {}

    public function readThrough(string $accountId, DiscussionId $discussionId, CategoryId $categoryId): int
    {
        $own = (int) DB::table(self::DISCUSSIONS)
            ->where('user_id', $accountId)
            ->where('discussion_id', $discussionId->value)
            ->value('last_read_number');

        $markedAt = DB::table(self::CATEGORIES)
            ->where('user_id', $accountId)
            ->where('category_id', $categoryId->value)
            ->value('marked_read_at');

        if ($markedAt === null) {
            return $own;
        }

        // Posts published up to the category mark were read, whatever their status now, as far
        // as the first one published after it: a merge appends older posts after newer ones,
        // and those newer ones were not read
        $posts = DB::table('forum_posts')->where('discussion_id', $discussionId->value)->whereNotNull('number');
        $firstUnread = (clone $posts)->where('published_at', '>', $markedAt)->min('number');
        $marked = is_numeric($firstUnread)
            ? (int) $firstUnread - 1
            : (int) (clone $posts)->max('number');

        return max($own, $marked);
    }

    public function advance(string $accountId, DiscussionId $discussionId, int $number, DateTimeImmutable $at): void
    {
        if ($number < 1) {
            return;
        }

        $readAt = $this->stored($at);

        if ($this->raise($accountId, $discussionId->value, $number, $readAt) > 0) {
            return;
        }

        // Ignored when the row exists (at or past the number, or written by another request
        // meanwhile), so no failed insert ever aborts a surrounding transaction
        $inserted = DB::table(self::DISCUSSIONS)->insertOrIgnore([
            'user_id' => $accountId,
            'discussion_id' => $discussionId->value,
            'last_read_number' => $number,
            'read_at' => $readAt,
        ]);

        if ($inserted === 0) {
            $this->raise($accountId, $discussionId->value, $number, $readAt);
        }
    }

    public function markCategoriesRead(string $accountId, array $categoryIds, DateTimeImmutable $at): void
    {
        if ($categoryIds === []) {
            return;
        }

        $markedAt = $this->stored($at);
        $rows = array_map(static fn (CategoryId $id): array => [
            'user_id' => $accountId,
            'category_id' => $id->value,
            'marked_read_at' => $markedAt,
        ], $categoryIds);

        DB::table(self::CATEGORIES)->upsert($rows, ['user_id', 'category_id'], ['marked_read_at']);
    }

    private function raise(string $accountId, string $discussionId, int $number, string $readAt): int
    {
        return DB::table(self::DISCUSSIONS)
            ->where('user_id', $accountId)
            ->where('discussion_id', $discussionId)
            ->where('last_read_number', '<', $number)
            ->update(['last_read_number' => $number, 'read_at' => $readAt]);
    }

    private function stored(DateTimeImmutable $at): string
    {
        return $this->timezone->toStorage($at)->format('Y-m-d H:i:s');
    }
}
