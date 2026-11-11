<?php

declare(strict_types=1);

namespace Modules\Forum\Application\Contracts;

use DateTimeImmutable;
use Modules\Forum\Domain\Enums\CategoryFollowOrigin;
use Modules\Forum\Domain\Enums\FollowLevel;
use Modules\Forum\Domain\ValueObjects\CategoryId;
use Modules\Forum\Domain\ValueObjects\DiscussionId;

/**
 * Who follows which discussions and categories, and whether posting follows a discussion.
 * Only «following» and «normal» are stored per discussion (muting is the core's mute of the
 * discussion); a discussion with no row is «normal» too, but posting may still follow it.
 */
interface FollowsInterface
{
    /**
     * What the person chose for the discussion, or null when they never chose.
     */
    public function discussionLevel(string $accountId, DiscussionId $discussionId): ?FollowLevel;

    /**
     * @param  FollowLevel  $level  Following or Normal
     */
    public function setDiscussionLevel(string $accountId, DiscussionId $discussionId, FollowLevel $level, DateTimeImmutable $at): void;

    /**
     * Follows the discussion unless the person already chose something for it; true when it did.
     */
    public function followUnlessChosen(string $accountId, DiscussionId $discussionId, DateTimeImmutable $at): bool;

    /**
     * Claims the reply mail for a post: true, recording the post, when no reply mail of the
     * discussion went out since the person last read it (up to $readThrough); false otherwise.
     */
    public function claimReplyMail(string $accountId, DiscussionId $discussionId, int $postNumber, int $readThrough): bool;

    /**
     * Accounts following the discussion.
     *
     * @return list<string>
     */
    public function discussionFollowers(DiscussionId $discussionId): array;

    /**
     * Of the discussions given, those the person follows.
     *
     * @param  list<DiscussionId>  $discussionIds
     * @return list<string>
     */
    public function followedAmong(string $accountId, array $discussionIds): array;

    /**
     * The discussions the person follows, most recently followed first.
     *
     * @return list<string>
     */
    public function followedDiscussionIds(string $accountId, int $limit): array;

    public function followsCategory(string $accountId, CategoryId $categoryId): bool;

    public function categoryFollowOrigin(string $accountId, CategoryId $categoryId): ?CategoryFollowOrigin;

    /**
     * Follows the category; true when it was not followed before. An existing follow keeps its origin.
     */
    public function followCategory(string $accountId, CategoryId $categoryId, CategoryFollowOrigin $origin, DateTimeImmutable $at): bool;

    /**
     * Stops following the category; with an origin, only a follow that came that way.
     */
    public function unfollowCategory(string $accountId, CategoryId $categoryId, ?CategoryFollowOrigin $origin = null): void;

    /**
     * Accounts following any of the categories, each once.
     *
     * @param  list<CategoryId>  $categoryIds
     * @return list<string>
     */
    public function categoryFollowers(array $categoryIds): array;

    /**
     * @return list<string>
     */
    public function followedCategoryIds(string $accountId): array;

    /**
     * Whether starting or replying to a discussion follows it (on unless turned off).
     */
    public function autoFollows(string $accountId): bool;

    public function setAutoFollows(string $accountId, bool $autoFollows, DateTimeImmutable $at): void;
}
