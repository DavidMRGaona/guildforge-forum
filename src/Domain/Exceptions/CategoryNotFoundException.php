<?php

declare(strict_types=1);

namespace Modules\Forum\Domain\Exceptions;

use RuntimeException;

final class CategoryNotFoundException extends RuntimeException
{
    public static function withId(string $id): self
    {
        return new self("Forum category {$id} does not exist.");
    }
}
