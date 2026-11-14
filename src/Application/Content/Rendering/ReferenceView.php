<?php

declare(strict_types=1);

namespace Modules\Forum\Application\Content\Rendering;

/**
 * What a reader is shown of a referenced post: its current number, and where it is (its place,
 * its preview and its discussion's title) only when the reader may read it.
 */
final readonly class ReferenceView
{
    public function __construct(
        public ?int $number,
        public ?string $url = null,
        public ?string $previewUrl = null,
        public ?string $discussionTitle = null,
    ) {}
}
