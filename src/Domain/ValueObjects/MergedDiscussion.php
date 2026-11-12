<?php

declare(strict_types=1);

namespace Modules\Forum\Domain\ValueObjects;

/**
 * A discussion moderation merged into another: the one it was, and the one its posts live in
 * now (null once that one was deleted too).
 */
final readonly class MergedDiscussion
{
    public function __construct(
        public DiscussionId $from,
        public ?DiscussionId $into,
    ) {}
}
