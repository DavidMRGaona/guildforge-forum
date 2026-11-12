<?php

declare(strict_types=1);

namespace Modules\Forum\Application\Search;

use DateTimeImmutable;

/**
 * One result of the forum's own search, already checked against what the reader may read.
 */
final readonly class ForumSearchHitDTO
{
    /**
     * @param  list<array{text: string, match: bool}>  $snippet  plain text segments, never HTML
     */
    public function __construct(
        public bool $isReply,
        public string $title,
        public string $url,
        public string $categoryId,
        public ?int $replyNumber,
        public array $snippet,
        public ?DateTimeImmutable $publishedAt,
    ) {}
}
