<?php

declare(strict_types=1);

namespace Modules\Forum\Domain\Exceptions;

use DomainException;
use Modules\Forum\Domain\Enums\PostStatus;

final class InvalidPostException extends DomainException
{
    private function __construct(string $message)
    {
        parent::__construct($message);
    }

    public static function numberNotPositive(int $value): self
    {
        return new self("A post number starts at 1 ({$value} given).");
    }

    public static function notADocument(): self
    {
        return new self('A post body is an editor document (type "doc").');
    }

    public static function textTooLong(int $max): self
    {
        return new self("A post has {$max} characters of text at most.");
    }

    public static function invalidAuthor(string $authorId): self
    {
        return new self("'{$authorId}' is not an account id.");
    }

    public static function numberMismatch(PostStatus $status): self
    {
        return $status === PostStatus::Pending
            ? new self('A post awaiting approval has no number or publication time yet.')
            : new self("A {$status->value} post always has a number and a publication time.");
    }

    public static function cannotChange(string $postId, PostStatus $from, string $action): self
    {
        return new self("Post {$postId} cannot {$action} while {$from->value}.");
    }

    public static function notPending(string $postId): self
    {
        return new self("Post {$postId} is not awaiting approval: only those are discarded, so readers never see a gap in the numbering.");
    }
}
