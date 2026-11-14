<?php

declare(strict_types=1);

namespace Modules\Forum\Application\DTOs;

use Modules\Forum\Domain\Entities\Discussion;
use Modules\Forum\Domain\Entities\Post;
use Modules\Forum\Domain\Enums\SubjectConversationMode;
use Modules\Forum\Domain\ValueObjects\PublicationKey;

/**
 * The end of a publication's discussion as its page shows it: a run of its last posts (or of
 * those before a number, when the page asks for earlier ones), and what the reader may do there.
 */
final readonly class SubjectConversationDTO
{
    /**
     * @param  list<Post>  $posts  numbered ones in order, then (on the latest run) those awaiting approval the reader may see
     * @param  list<PostContentDTO>  $contents  what each of those posts shows to the reader, in the same order
     * @param  int  $olderCount  how many numbered posts come before the run (the forum's opening post aside)
     * @param  int|null  $lastReadNumber  the reader's mark; null for guests
     * @param  SubjectConversationMode  $mode  read or reply (a hidden one is never built)
     */
    public function __construct(
        public PublicationKey $key,
        public Discussion $discussion,
        public array $posts,
        public array $contents,
        public int $olderCount,
        public int $highestNumber,
        public ?int $lastReadNumber,
        public bool $canPost,
        public ?SubjectClosedReason $closedReason,
        public SubjectConversationMode $mode,
    ) {}

    /** Read only: the page offers no reply box, quoting or editing */
    public function isReadOnly(): bool
    {
        return $this->mode === SubjectConversationMode::Read;
    }

    /**
     * The number earlier posts are asked before: the lowest numbered post of the run.
     */
    public function olderBefore(): ?int
    {
        if ($this->olderCount === 0) {
            return null;
        }

        foreach ($this->posts as $post) {
            if ($post->number() !== null) {
                return $post->number()->value;
            }
        }

        return null;
    }
}
