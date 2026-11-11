<?php

declare(strict_types=1);

namespace Modules\Forum\Application\Queries;

use InvalidArgumentException;

/**
 * Which discussions a list shows. The category ids are the ones the reader may read (decided by
 * the forum's access rules before asking); hidden discussions only show in the moderated ones.
 */
final readonly class DiscussionListCriteria
{
    public const int MAX_PINNED = 50;

    /**
     * @param  list<string>  $categoryIds
     * @param  list<string>  $moderatedCategoryIds
     * @param  string|null  $readerId  the signed-in reader, whose read marks are joined in
     * @param  bool  $pinnedFirst  pinned discussions come apart, before the others, on the first page
     * @param  bool  $onlyFollowed  only the discussions the reader follows («Siguiendo»)
     */
    public function __construct(
        public array $categoryIds,
        public array $moderatedCategoryIds,
        public int $limit,
        public ?string $readerId = null,
        public bool $onlyUnread = false,
        public ?string $topicKey = null,
        public bool $pinnedFirst = false,
        public ?DiscussionCursor $after = null,
        public bool $onlyFollowed = false,
    ) {
        if ($limit < 1) {
            throw new InvalidArgumentException('A discussion list needs a positive limit.');
        }

        if ($onlyUnread && $readerId === null) {
            throw new InvalidArgumentException('Only a signed-in reader has unread discussions.');
        }

        if ($onlyFollowed && $readerId === null) {
            throw new InvalidArgumentException('Only a signed-in reader follows discussions.');
        }
    }
}
