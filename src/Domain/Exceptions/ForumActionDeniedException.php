<?php

declare(strict_types=1);

namespace Modules\Forum\Domain\Exceptions;

use RuntimeException;

/**
 * Someone who may read a discussion tried something they may not do in it (reply where they
 * cannot write, edit someone else's post, see versions without moderating).
 */
final class ForumActionDeniedException extends RuntimeException
{
    public static function because(string $action): self
    {
        return new self("Not allowed to {$action}.");
    }
}
