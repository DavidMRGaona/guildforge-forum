<?php

declare(strict_types=1);

namespace Modules\Forum\Infrastructure\Services;

use App\Application\Services\TimezoneServiceInterface;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Modules\Forum\Application\Contracts\FollowsInterface;
use Modules\Forum\Domain\Enums\CategoryFollowOrigin;
use Modules\Forum\Domain\Enums\FollowLevel;
use Modules\Forum\Domain\ValueObjects\CategoryId;
use Modules\Forum\Domain\ValueObjects\DiscussionId;

/**
 * Follows in `forum_discussion_follows` and `forum_category_follows`, and the auto-follow
 * choice in `forum_user_preferences` (no row: on). Inserts that meet an existing row are
 * ignored, so two requests at once never fail.
 */
final readonly class EloquentFollows implements FollowsInterface
{
    public const string DISCUSSIONS = 'forum_discussion_follows';

    public const string CATEGORIES = 'forum_category_follows';

    public const string PREFERENCES = 'forum_user_preferences';

    public function __construct(
        private TimezoneServiceInterface $timezone,
    ) {}

    public function discussionLevel(string $accountId, DiscussionId $discussionId): ?FollowLevel
    {
        $level = DB::table(self::DISCUSSIONS)
            ->where('user_id', strtolower($accountId))
            ->where('discussion_id', $discussionId->value)
            ->value('level');

        return is_string($level) ? FollowLevel::tryFrom($level) : null;
    }

    public function setDiscussionLevel(string $accountId, DiscussionId $discussionId, FollowLevel $level, DateTimeImmutable $at): void
    {
        // Muting is not stored here: whoever mutes keeps «normal», so posting does not follow again
        $stored = $level === FollowLevel::Following ? FollowLevel::Following : FollowLevel::Normal;

        DB::table(self::DISCUSSIONS)->upsert(
            [['user_id' => strtolower($accountId), 'discussion_id' => $discussionId->value, 'level' => $stored->value, 'updated_at' => $this->stored($at)]],
            ['user_id', 'discussion_id'],
            ['level', 'updated_at'],
        );
    }

    public function followUnlessChosen(string $accountId, DiscussionId $discussionId, DateTimeImmutable $at): bool
    {
        return DB::table(self::DISCUSSIONS)->insertOrIgnore([
            'user_id' => strtolower($accountId),
            'discussion_id' => $discussionId->value,
            'level' => FollowLevel::Following->value,
            'updated_at' => $this->stored($at),
        ]) > 0;
    }

    public function claimReplyMail(string $accountId, DiscussionId $discussionId, int $postNumber, int $readThrough): bool
    {
        // One conditional update: two replies delivered at once claim one mail between them
        return DB::table(self::DISCUSSIONS)
            ->where('user_id', strtolower($accountId))
            ->where('discussion_id', $discussionId->value)
            ->where(static function ($query) use ($readThrough): void {
                $query->whereNull('mailed_through')->orWhere('mailed_through', '<=', $readThrough);
            })
            ->update(['mailed_through' => $postNumber]) > 0;
    }

    public function discussionFollowers(DiscussionId $discussionId): array
    {
        return $this->ids(DB::table(self::DISCUSSIONS)
            ->where('discussion_id', $discussionId->value)
            ->where('level', FollowLevel::Following->value)
            ->pluck('user_id')
            ->all());
    }

    public function followedAmong(string $accountId, array $discussionIds): array
    {
        if ($discussionIds === []) {
            return [];
        }

        return $this->ids(DB::table(self::DISCUSSIONS)
            ->where('user_id', strtolower($accountId))
            ->whereIn('discussion_id', array_map(static fn (DiscussionId $id): string => $id->value, $discussionIds))
            ->where('level', FollowLevel::Following->value)
            ->pluck('discussion_id')
            ->all());
    }

    public function followedDiscussionIds(string $accountId, int $limit): array
    {
        return $this->ids(DB::table(self::DISCUSSIONS)
            ->where('user_id', strtolower($accountId))
            ->where('level', FollowLevel::Following->value)
            ->orderByDesc('updated_at')
            ->orderBy('discussion_id')
            ->limit($limit)
            ->pluck('discussion_id')
            ->all());
    }

    public function followsCategory(string $accountId, CategoryId $categoryId): bool
    {
        return $this->categoryFollowOrigin($accountId, $categoryId) instanceof CategoryFollowOrigin;
    }

    public function categoryFollowOrigin(string $accountId, CategoryId $categoryId): ?CategoryFollowOrigin
    {
        $origin = DB::table(self::CATEGORIES)
            ->where('user_id', strtolower($accountId))
            ->where('category_id', $categoryId->value)
            ->value('origin');

        // An origin the module no longer knows still follows; leaving a roster never takes it back
        return is_string($origin) ? (CategoryFollowOrigin::tryFrom($origin) ?? CategoryFollowOrigin::Person) : null;
    }

    public function followCategory(string $accountId, CategoryId $categoryId, CategoryFollowOrigin $origin, DateTimeImmutable $at): bool
    {
        return DB::table(self::CATEGORIES)->insertOrIgnore([
            'user_id' => strtolower($accountId),
            'category_id' => $categoryId->value,
            'origin' => $origin->value,
            'created_at' => $this->stored($at),
        ]) > 0;
    }

    public function unfollowCategory(string $accountId, CategoryId $categoryId, ?CategoryFollowOrigin $origin = null): void
    {
        DB::table(self::CATEGORIES)
            ->where('user_id', strtolower($accountId))
            ->where('category_id', $categoryId->value)
            ->when($origin instanceof CategoryFollowOrigin, static fn ($query) => $query->where('origin', $origin?->value))
            ->delete();
    }

    public function categoryFollowers(array $categoryIds): array
    {
        if ($categoryIds === []) {
            return [];
        }

        return $this->ids(DB::table(self::CATEGORIES)
            ->whereIn('category_id', array_map(static fn (CategoryId $id): string => $id->value, $categoryIds))
            ->distinct()
            ->pluck('user_id')
            ->all());
    }

    public function followedCategoryIds(string $accountId): array
    {
        return $this->ids(DB::table(self::CATEGORIES)
            ->where('user_id', strtolower($accountId))
            ->pluck('category_id')
            ->all());
    }

    public function autoFollows(string $accountId): bool
    {
        $value = DB::table(self::PREFERENCES)->where('user_id', strtolower($accountId))->value('auto_follow');

        return $value === null || (bool) $value;
    }

    public function setAutoFollows(string $accountId, bool $autoFollows, DateTimeImmutable $at): void
    {
        DB::table(self::PREFERENCES)->upsert(
            [['user_id' => strtolower($accountId), 'auto_follow' => $autoFollows, 'updated_at' => $this->stored($at)]],
            ['user_id'],
            ['auto_follow', 'updated_at'],
        );
    }

    /**
     * @param  array<mixed>  $values
     * @return list<string>
     */
    private function ids(array $values): array
    {
        return array_values(array_unique(array_map(static fn (mixed $id): string => strtolower(is_scalar($id) ? (string) $id : ''), $values)));
    }

    private function stored(DateTimeImmutable $at): string
    {
        return $this->timezone->toStorage($at)->format('Y-m-d H:i:s');
    }
}
