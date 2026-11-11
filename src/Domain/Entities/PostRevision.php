<?php

declare(strict_types=1);

namespace Modules\Forum\Domain\Entities;

use DateTimeImmutable;
use Modules\Forum\Domain\Exceptions\InvalidPostException;
use Modules\Forum\Domain\ValueObjects\AccountId;
use Modules\Forum\Domain\ValueObjects\PostBody;
use Modules\Forum\Domain\ValueObjects\PostId;

/**
 * A version of a post that an edit replaced, kept so moderators can see what it said. Revision
 * 1 is what the post said first; the post's current body is the latest version and has no
 * revision. The version of revision n + 1 (or the current one, after the last) was written by
 * the editor of revision n at its replacement time.
 */
final readonly class PostRevision
{
    private ?string $editorId;

    /**
     * @param  int  $number  from 1, in the order the edits happened
     * @param  string|null  $editorId  who made the edit that replaced this version; null once that account is gone
     * @param  DateTimeImmutable  $replacedAt  when that edit happened
     *
     * @throws InvalidPostException
     */
    public function __construct(
        public PostId $postId,
        public int $number,
        public PostBody $body,
        ?string $editorId,
        public DateTimeImmutable $replacedAt,
    ) {
        if ($number < 1) {
            throw InvalidPostException::numberNotPositive($number);
        }

        $this->editorId = $editorId === null
            ? null
            : (AccountId::normalize($editorId) ?? throw InvalidPostException::invalidAuthor($editorId));
    }

    public function editorId(): ?string
    {
        return $this->editorId;
    }
}
