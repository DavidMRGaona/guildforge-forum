<?php

declare(strict_types=1);

namespace Modules\Forum\Application\DTOs;

use Modules\Forum\Domain\Entities\Category;
use Modules\Forum\Domain\Entities\Discussion;
use Modules\Forum\Domain\Entities\Post;
use Modules\Forum\Domain\ValueObjects\DiscussionTopic;

/**
 * One page of a discussion as a reader sees it. Pages hold a fixed run of post numbers
 * (1–20, 21–40…), so a link to a number always opens the same page; where moderation moved
 * posts away the page holds fewer, and `moved` says where they went. `focusNumber` is the post
 * the page should scroll to: the one asked for, or the first the reader has not read.
 */
final readonly class ConversationPageDTO
{
    /**
     * @param  list<Post>  $posts  in order: numbered ones, then (on the last page) those awaiting approval the reader may see
     * @param  list<PostContentDTO>  $contents  what each of those posts shows to the reader, in the same order
     * @param  list<DiscussionTopic>  $topics
     * @param  list<MovedPostsDTO>  $moved  the posts of the page's numbers that moderation moved away
     */
    public function __construct(
        public Discussion $discussion,
        public Category $category,
        public array $topics,
        public array $posts,
        public array $contents,
        public int $pageStart,
        public int $perPage,
        public int $highestNumber,
        public ?int $focusNumber,
        public ?int $lastReadNumber,
        public bool $canPost,
        public bool $canModerate,
        public bool $guestsRead,
        public array $moved = [],
    ) {}

    public function hasPrevious(): bool
    {
        return $this->pageStart > 1;
    }

    public function hasNext(): bool
    {
        return $this->pageStart + $this->perPage <= $this->highestNumber;
    }

    public function pageCount(): int
    {
        return max(1, (int) ceil($this->highestNumber / $this->perPage));
    }

    public function page(): int
    {
        return intdiv($this->pageStart - 1, $this->perPage) + 1;
    }
}
