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

    /**
     * Controls, line and paragraph separators, and the marks that turn the direction of the text
     * around it (embeddings, overrides, isolates): a title stays one plain line. Joiners and
     * variation selectors are left alone, since emoji sequences need them.
     */
    private const string FORBIDDEN = '/[\p{Cc}\p{Zl}\p{Zp}\x{061C}\x{200E}\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}]/u';

    private function __construct(
        public string $value,
    ) {}

    /**
     * The title typed, trimmed; null when nothing is left, which means no title.
     *
     * @throws InvalidModerationRecordException for a title that is not UTF-8, too long, or holds a
     *                                          line break, a control or a direction mark
     */
    public static function fromInput(?string $input): ?self
    {
        $value = trim($input ?? '');
        if ($value === '') {
            return null;
        }
        // Checked first: on text that is not UTF-8 the pattern fails (false), which must not pass
        if (! mb_check_encoding($value, 'UTF-8')
            || mb_strlen($value) > self::MAX_LENGTH
            || preg_match(self::FORBIDDEN, $value) !== 0) {
            throw InvalidModerationRecordException::invalidTitle(self::MAX_LENGTH);
        }

        return new self($value);
    }
}
