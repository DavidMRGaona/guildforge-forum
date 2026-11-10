<?php

declare(strict_types=1);

namespace Modules\Forum\Domain\ValueObjects;

use Modules\Forum\Domain\Exceptions\InvalidDiscussionException;
use Stringable;

final readonly class DiscussionTitle implements Stringable
{
    public const int MAX_LENGTH = 160;

    public string $value;

    /**
     * @throws InvalidDiscussionException for a blank or too long title
     */
    public function __construct(string $value)
    {
        $value = trim($value);

        if ($value === '') {
            throw InvalidDiscussionException::blankTitle();
        }

        if (mb_strlen($value) > self::MAX_LENGTH) {
            throw InvalidDiscussionException::titleTooLong(self::MAX_LENGTH);
        }

        $this->value = $value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
