<?php

declare(strict_types=1);

namespace Modules\Forum\Domain\Exceptions;

use RuntimeException;

/**
 * A moderator asked for something moderation never does (moving a discussion where it may not
 * go, renaming it to a title out of bounds). $reason is a stable key for the message.
 */
final class ModerationRefusedException extends RuntimeException
{
    private function __construct(string $message, public readonly string $reason)
    {
        parent::__construct($message);
    }

    public static function moveTarget(string $categoryId): self
    {
        return new self("A discussion cannot be moved into category {$categoryId}.", 'move_target');
    }

    public static function sameCategory(): self
    {
        return new self('The discussion is in that category already.', 'same_category');
    }

    public static function automaticDiscussion(string $discussionId): self
    {
        return new self("Discussion {$discussionId} belongs to a publication and stays where the forum put it.", 'automatic_discussion');
    }

    public static function splitSelection(): self
    {
        return new self('Only numbered posts after the first one, each once, can be split off.', 'split_selection');
    }

    public static function mergeTarget(string $discussionId): self
    {
        return new self("Discussion {$discussionId} cannot take this merge.", 'merge_target');
    }

    public static function title(): self
    {
        return new self('The title is out of bounds.', 'title');
    }

    public static function reasonTooLong(int $max): self
    {
        return new self("The reason is limited to {$max} characters.", 'reason');
    }

    public static function awaitingApproval(string $discussionId): self
    {
        return new self("Discussion {$discussionId} is awaiting approval: approve or reject its first post.", 'awaiting_approval');
    }

    public static function notSuspendable(string $id): self
    {
        return new self("{$id} cannot be suspended in the forum.", 'not_suspendable');
    }

    public static function notReportable(string $postId): self
    {
        return new self("Post {$postId} cannot be reported by this person.", 'not_reportable');
    }

    public static function suspended(): self
    {
        return new self('A suspended person does not report.', 'suspended');
    }
}
