<?php

declare(strict_types=1);

namespace Modules\Forum\Domain\ValueObjects;

use Modules\Forum\Domain\Exceptions\InvalidCategoryException;
use Stringable;

/**
 * The category's part of a forum address: lowercase letters and digits in words joined by
 * single dashes, 64 characters at most.
 */
final readonly class CategorySlug implements Stringable
{
    public const int MAX_LENGTH = 64;

    private const string PATTERN = '/^[a-z0-9]+(?:-[a-z0-9]+)*\z/';

    /**
     * @throws InvalidCategoryException
     */
    public function __construct(
        public string $value,
    ) {
        if (strlen($value) > self::MAX_LENGTH || preg_match(self::PATTERN, $value) !== 1) {
            throw InvalidCategoryException::invalidSlug($value);
        }
    }

    /**
     * @throws InvalidCategoryException when the name has no letter or digit to make it from
     */
    public static function fromName(string $name): self
    {
        $ascii = transliterator_transliterate('Any-Latin; Latin-ASCII; Lower()', $name);
        $words = preg_split('/[^a-z0-9]+/', is_string($ascii) ? $ascii : '', -1, PREG_SPLIT_NO_EMPTY);
        $slug = implode('-', $words === false ? [] : $words);

        return new self(rtrim(substr($slug, 0, self::MAX_LENGTH), '-'));
    }

    public function __toString(): string
    {
        return $this->value;
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
