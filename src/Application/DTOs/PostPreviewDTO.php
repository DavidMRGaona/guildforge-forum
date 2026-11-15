<?php

declare(strict_types=1);

namespace Modules\Forum\Application\DTOs;

use Modules\Forum\Domain\Entities\Discussion;
use Modules\Forum\Domain\Entities\Post;

/**
 * A post as a reference opens it: its content for the reader and, when it is long, how many
 * images and embeds were left out of it.
 */
final readonly class PostPreviewDTO
{
    public function __construct(
        public Post $post,
        public Discussion $discussion,
        public PostContentDTO $content,
        public bool $truncated,
        public int $hiddenImages,
        public int $hiddenEmbeds,
    ) {}
}
