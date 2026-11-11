<?php

declare(strict_types=1);

namespace Modules\Forum\Domain\Events;

/**
 * Someone reported a post (after the commit). The notices tell its moderators; nothing personal
 * about the reporter goes with it.
 */
final readonly class ForumPostReported
{
    public function __construct(
        public string $reportId,
        public string $postId,
        public string $discussionId,
        public string $categoryId,
        public string $reason,
    ) {}
}
