<?php

declare(strict_types=1);

namespace Modules\Forum\Domain\ValueObjects;

use Modules\Forum\Domain\Exceptions\InvalidConversationException;
use Stringable;

/**
 * What a private conversation is about, on one line.
 */
final readonly class ConversationTitle implements Stringable
{
    public const int MAX_LENGTH = 120;

    public string $value;

    /**
     * @throws InvalidConversationException for a blank or too long title
     */
    public function __construct(string $value)
    {
        $value = trim(preg_replace('/\s+/u', ' ', $value) ?? '');

        if ($value === '') {
            throw InvalidConversationException::blankTitle();
        }

        if (mb_strlen($value) > self::MAX_LENGTH) {
            throw InvalidConversationException::titleTooLong(self::MAX_LENGTH);
        }

        $this->value = $value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
