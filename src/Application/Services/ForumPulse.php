<?php

declare(strict_types=1);

namespace Modules\Forum\Application\Services;

use Illuminate\Contracts\Auth\Authenticatable;
use Modules\Forum\Application\Contracts\FollowsInterface;
use Modules\Forum\Application\DTOs\CategoryAccessDTO;
use Modules\Forum\Application\Queries\ForumActivityQueryInterface;

/**
 * What changed in the forum for a signed-in person, for the menu and the lists: how many
 * discussions they follow have posts they did not read (counted up to a cap), and the last post
 * of the discussions a list shows, so the list can mark the ones that moved. Everything starts
 * from the categories the access rules let the person read.
 */
final readonly class ForumPulse
{
    /** The unread count stops here: the menu shows «99+» and no more is worth counting */
    public const int UNREAD_CAP = 100;

    /** Discussions a list may ask about (a page, with its pinned ones) */
    public const int MAX_DISCUSSIONS = 100;

    public function __construct(
        private ForumBrowser $browser,
        private ForumActivityQueryInterface $activity,
        private FollowsInterface $follows,
    ) {}

    /**
     * @param  list<string>  $discussionIds  the discussions a list shows, if any
     * @return array{unreadFollowed: int, discussions: array<string, int>}
     */
    public function summary(Authenticatable $reader, array $discussionIds = []): array
    {
        $readerId = (string) ForumBrowser::accountId($reader);
        $readable = $this->browser->readableCategories($reader);
        $categoryIds = array_map(static fn (CategoryAccessDTO $access): string => $access->category->id()->value, $readable);
        $moderated = array_values(array_map(
            static fn (CategoryAccessDTO $access): string => $access->category->id()->value,
            array_filter($readable, static fn (CategoryAccessDTO $access): bool => $access->canModerate),
        ));

        return [
            'unreadFollowed' => count($this->activity->unreadFollowedIds(
                $readerId,
                $categoryIds,
                $moderated,
                $this->follows->followedCategoryIds($readerId),
                self::UNREAD_CAP,
            )),
            'discussions' => $this->activity->lastPostNumbers(array_slice($discussionIds, 0, self::MAX_DISCUSSIONS), $categoryIds, $moderated),
        ];
    }
}
