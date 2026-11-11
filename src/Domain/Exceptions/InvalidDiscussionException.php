<?php

declare(strict_types=1);

namespace Modules\Forum\Domain\Exceptions;

use DomainException;

final class InvalidDiscussionException extends DomainException
{
    private function __construct(string $message)
    {
        parent::__construct($message);
    }

    public static function blankTitle(): self
    {
        return new self('A discussion needs a title.');
    }

    public static function titleTooLong(int $max): self
    {
        return new self("A discussion title has {$max} characters at most.");
    }

    public static function titleOutOfBounds(int $min, int $max): self
    {
        return new self("A new discussion's title has {$min} to {$max} characters.");
    }

    public static function invalidNumber(int $number): self
    {
        return new self("{$number} cannot be the discussion's number.");
    }

    public static function invalidAuthor(string $authorId): self
    {
        return new self("'{$authorId}' is not an account id.");
    }

    public static function alreadyClosed(string $discussionId): self
    {
        return new self("Discussion {$discussionId} is already closed.");
    }

    public static function alreadyOpen(string $discussionId): self
    {
        return new self("Discussion {$discussionId} is already open.");
    }

    public static function invalidTopic(string $key): self
    {
        return new self("'{$key}' is not a discussion tag: a key of up to 64 characters from [a-z0-9._-] and a label of up to 120.");
    }

    public static function notAwaitingApproval(string $discussionId): self
    {
        return new self("Discussion {$discussionId} is not awaiting approval.");
    }
}
