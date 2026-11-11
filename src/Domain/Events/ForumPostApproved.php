<?php

declare(strict_types=1);

namespace Modules\Forum\Domain\Events;

/**
 * A post that awaited approval was published (after the commit), so the notices can tell its
 * author. When it opened a discussion, the discussion was approved with it.
 */
final readonly class ForumPostApproved
{
    public function __construct(
        public string $postId,
        public string $discussionId,
        public ?string $authorId,
        public bool $openedDiscussion,
    ) {}
}
