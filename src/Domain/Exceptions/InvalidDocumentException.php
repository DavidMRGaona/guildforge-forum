<?php

declare(strict_types=1);

namespace Modules\Forum\Domain\Exceptions;

use DomainException;

/**
 * A post document the editor's schema does not accept. It is refused as it came (422), never
 * repaired: the editor only produces valid documents, so anything else was built by hand.
 *
 * The reason is a stable code the client can translate; the path says where in the document
 * («content.2.content.0»), or is empty for the whole document.
 */
final class InvalidDocumentException extends DomainException
{
    public const string NOT_A_DOCUMENT = 'not_a_document';

    public const string TOO_LARGE = 'too_large';

    public const string TOO_DEEP = 'too_deep';

    public const string TOO_MANY_NODES = 'too_many_nodes';

    public const string TOO_LONG = 'too_long';

    public const string EMPTY = 'empty';

    public const string UNKNOWN_NODE = 'unknown_node';

    public const string UNKNOWN_MARK = 'unknown_mark';

    public const string UNEXPECTED_KEY = 'unexpected_key';

    public const string NOT_ALLOWED_HERE = 'not_allowed_here';

    public const string MISSING_CONTENT = 'missing_content';

    public const string INVALID_TEXT = 'invalid_text';

    public const string INVALID_ATTRIBUTE = 'invalid_attribute';

    public const string INVALID_LINK = 'invalid_link';

    public const string INVALID_IMAGE = 'invalid_image';

    public const string TOO_MANY_IMAGES = 'too_many_images';

    public const string TOO_MANY_MENTIONS = 'too_many_mentions';

    public const string TOO_MANY_QUOTES = 'too_many_quotes';

    public const string UNKNOWN_ACCOUNT = 'unknown_account';

    public const string UNKNOWN_POST = 'unknown_post';

    public const string INVALID_QUOTE = 'invalid_quote';

    public const string TOO_MANY_ROLLS = 'too_many_rolls';

    public const string UNKNOWN_ROLL = 'unknown_roll';

    private function __construct(
        public readonly string $reason,
        public readonly string $path,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function because(string $reason, string $path, string $detail): self
    {
        return new self($reason, $path, $path === '' ? $detail : "{$detail} (at {$path})");
    }
}
