<?php

declare(strict_types=1);

namespace Modules\Forum\Application\DTOs;

/**
 * What someone sends to start a discussion. `document` is the editor's document as sent.
 */
final readonly class StartDiscussionDTO
{
    /**
     * @param  list<string>  $topicKeys
     */
    public function __construct(
        public string $categoryId,
        public string $title,
        public mixed $document,
        public array $topicKeys = [],
        public bool $pinned = false,
        public bool $closed = false,
    ) {}
}
