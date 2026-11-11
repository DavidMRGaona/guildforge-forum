<?php

declare(strict_types=1);

namespace Modules\Forum\Application\Content\Rendering;

/**
 * Who wrote a quoted post and where it is, as far as the reader may know: both null when the
 * reader cannot see the original.
 */
final readonly class QuoteSource
{
    public function __construct(
        public ?string $authorName,
        public ?string $url,
    ) {}
}
