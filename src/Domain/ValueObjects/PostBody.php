<?php

declare(strict_types=1);

namespace Modules\Forum\Domain\ValueObjects;

use Modules\Forum\Domain\Exceptions\InvalidPostException;

/**
 * What a post says: the editor's document (TipTap JSON, never HTML) and its plain text, kept
 * for search and summaries. Which nodes a document may hold is decided by the editor's schema,
 * checked before a body is built; here only its outer shape is checked.
 */
final readonly class PostBody
{
    public const int MAX_PLAIN_TEXT_LENGTH = 65_000;

    public string $plainText;

    /**
     * @param  array<string, mixed>  $document
     *
     * @throws InvalidPostException
     */
    public function __construct(
        public array $document,
        string $plainText,
    ) {
        if (($document['type'] ?? null) !== 'doc') {
            throw InvalidPostException::notADocument();
        }

        $plainText = trim($plainText);
        if (mb_strlen($plainText) > self::MAX_PLAIN_TEXT_LENGTH) {
            throw InvalidPostException::textTooLong(self::MAX_PLAIN_TEXT_LENGTH);
        }

        $this->plainText = $plainText;
    }
}
