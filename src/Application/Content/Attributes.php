<?php

declare(strict_types=1);

namespace Modules\Forum\Application\Content;

use Modules\Forum\Domain\Exceptions\InvalidDocumentException;

/**
 * Checks for the attributes of nodes and marks. Each throws on the first problem and never
 * converts a value: "2" is not a heading level.
 */
final class Attributes
{
    /**
     * Only the given keys may appear, and the attributes must be an object, not a list.
     *
     * @param  array<array-key, mixed>  $attributes
     * @param  list<string>  $allowed
     *
     * @throws InvalidDocumentException
     */
    public static function only(array $attributes, array $allowed, string $path): void
    {
        if ($attributes !== [] && array_is_list($attributes)) {
            throw InvalidDocumentException::because(InvalidDocumentException::INVALID_ATTRIBUTE, $path, 'Attributes are an object');
        }

        foreach (array_keys($attributes) as $key) {
            if (! in_array($key, $allowed, true)) {
                throw InvalidDocumentException::because(InvalidDocumentException::INVALID_ATTRIBUTE, $path, "Unknown attribute '{$key}'");
            }
        }
    }

    /**
     * @param  array<array-key, mixed>  $attributes
     * @param  list<int>  $allowed
     *
     * @throws InvalidDocumentException
     */
    public static function intIn(array $attributes, string $key, array $allowed, string $path): void
    {
        $value = $attributes[$key] ?? null;

        if (! is_int($value) || ! in_array($value, $allowed, true)) {
            throw InvalidDocumentException::because(InvalidDocumentException::INVALID_ATTRIBUTE, $path, "'{$key}' is one of ".implode(', ', $allowed));
        }
    }

    /**
     * An optional whole number within bounds.
     *
     * @param  array<array-key, mixed>  $attributes
     *
     * @throws InvalidDocumentException
     */
    public static function optionalIntBetween(array $attributes, string $key, int $min, int $max, string $path): void
    {
        $value = $attributes[$key] ?? null;

        if ($value !== null && (! is_int($value) || $value < $min || $value > $max)) {
            throw InvalidDocumentException::because(InvalidDocumentException::INVALID_ATTRIBUTE, $path, "'{$key}' is a whole number from {$min} to {$max}");
        }
    }

    /**
     * An optional string matching the pattern.
     *
     * @param  array<array-key, mixed>  $attributes
     *
     * @throws InvalidDocumentException
     */
    public static function optionalMatching(array $attributes, string $key, string $pattern, string $path): void
    {
        $value = $attributes[$key] ?? null;

        if ($value !== null && (! is_string($value) || preg_match($pattern, $value) !== 1)) {
            throw InvalidDocumentException::because(InvalidDocumentException::INVALID_ATTRIBUTE, $path, "'{$key}' has an unexpected value");
        }
    }

    /**
     * An optional string of at most the given characters, without control characters.
     *
     * @param  array<array-key, mixed>  $attributes
     *
     * @throws InvalidDocumentException
     */
    public static function optionalText(array $attributes, string $key, int $maxLength, string $path): void
    {
        $value = $attributes[$key] ?? null;

        if ($value !== null && (! is_string($value) || mb_strlen($value) > $maxLength || preg_match('/[\x00-\x1F\x7F]/', $value) === 1)) {
            throw InvalidDocumentException::because(InvalidDocumentException::INVALID_ATTRIBUTE, $path, "'{$key}' is a text of {$maxLength} characters at most");
        }
    }

    /**
     * A required UUID, in lowercase (as the server hands them out).
     *
     * @param  array<array-key, mixed>  $attributes
     *
     * @throws InvalidDocumentException
     */
    public static function uuid(array $attributes, string $key, string $path): void
    {
        $value = $attributes[$key] ?? null;

        if (! is_string($value) || preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $value) !== 1) {
            throw InvalidDocumentException::because(InvalidDocumentException::INVALID_ATTRIBUTE, $path, "'{$key}' is an id");
        }
    }

    /**
     * A string attribute already validated, or null.
     *
     * @param  array<string, mixed>  $attributes
     */
    public static function string(array $attributes, string $key): ?string
    {
        $value = $attributes[$key] ?? null;

        return is_string($value) ? $value : null;
    }

    /**
     * An integer attribute already validated, or null.
     *
     * @param  array<string, mixed>  $attributes
     */
    public static function int(array $attributes, string $key): ?int
    {
        $value = $attributes[$key] ?? null;

        return is_int($value) ? $value : null;
    }
}
