<?php

declare(strict_types=1);

namespace Modules\Forum\Domain\ValueObjects;

use Modules\Forum\Domain\Exceptions\InvalidModerationRecordException;

/**
 * The short title whoever moderates the forum gives a person, shown next to their name: one line
 * of up to 30 characters, kept as typed (it is escaped wherever it is drawn).
 */
final readonly class MemberTitle
{
    public const int MAX_LENGTH = 30;

    private function __construct(
        public string $value,
    ) {}

    /**
     * The title typed, trimmed; null when nothing is left, which means no title.
     *
     * @throws InvalidModerationRecordException for a title too long or with a line break or control character
     */
    public static function fromInput(?string $input): ?self
    {
        $value = trim($input ?? '');
        if ($value === '') {
            return null;
        }
        if (mb_strlen($value) > self::MAX_LENGTH || preg_match('/\p{Cc}/u', $value) === 1) {
            throw InvalidModerationRecordException::invalidTitle(self::MAX_LENGTH);
        }

        return new self($value);
    }
}
