<?php

declare(strict_types=1);

namespace Modules\Forum\Domain\Exceptions;

use DomainException;

/**
 * A discussion could not be started because of one of the fields sent: `field` names it and
 * `reason` says why, for the form to show next to it.
 */
final class DiscussionNotStartedException extends DomainException
{
    public const string CATEGORY = 'category';

    public const string TOPICS = 'topics';

    public const string MODERATION = 'moderation';

    private function __construct(
        public readonly string $field,
        public readonly string $reason,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function categoryNotWritable(string $categoryId): self
    {
        return new self(self::CATEGORY, 'not_writable', "Discussions cannot be started in category {$categoryId} by this person.");
    }

    public static function unknownTopic(string $key): self
    {
        return new self(self::TOPICS, 'unknown_topic', "Topic {$key} cannot be given to a new discussion.");
    }

    public static function notModerator(): self
    {
        return new self(self::MODERATION, 'not_moderator', 'Only moderators pin or close a discussion when starting it.');
    }
}
