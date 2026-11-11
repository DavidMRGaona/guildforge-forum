<?php

declare(strict_types=1);

namespace Modules\Forum\Application\DTOs;

use Modules\Forum\Domain\Entities\Category;
use Modules\Forum\Domain\Entities\Discussion;
use Modules\Forum\Domain\Entities\Post;

/**
 * What a notice about a post shows, read when it is delivered: the post, where it is, who wrote
 * it, a short excerpt and its address (a path of the site and the absolute one for mail).
 */
final readonly class PostNoticeDTO
{
    public function __construct(
        public Post $post,
        public Discussion $discussion,
        public Category $category,
        public string $recipientId,
        public ?string $authorName,
        public string $excerpt,
        public string $path,
        public string $url,
    ) {}
}
