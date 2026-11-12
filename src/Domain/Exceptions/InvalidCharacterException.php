<?php

declare(strict_types=1);

namespace Modules\Forum\Domain\Exceptions;

use RuntimeException;

/**
 * A character that cannot be saved as sent, or a change it does not take. The reason is a code
 * the endpoints translate (forum::messages.characters.errors.<reason>) and the field it concerns.
 */
final class InvalidCharacterException extends RuntimeException
{
    public const string NAME = 'name';

    public const string DESCRIPTION = 'description';

    public const string CATEGORY = 'category';

    public const string PORTRAIT = 'portrait';

    public const string TOO_MANY = 'too_many';

    public const string HAS_POSTS = 'has_posts';

    public const string LOCKED = 'locked';

    public const string NOT_RETIRED = 'not_retired';

    public const string ALREADY_RETIRED = 'already_retired';

    private function __construct(
        public readonly string $reason,
        public readonly string $field,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function because(string $reason, string $field, string $message): self
    {
        return new self($reason, $field, $message);
    }
}
