<?php

declare(strict_types=1);

namespace Modules\Forum\Application\DTOs;

use Modules\Forum\Domain\Entities\Discussion;
use Modules\Forum\Domain\Entities\Post;

/**
 * The numbered posts that came after the last one a reader has.
 */
final readonly class NewPostsDTO
{
    /**
     * @param  list<Post>  $posts  in order, at most NewPostsReader::MAX
     * @param  list<PostContentDTO>  $contents  what each post shows to the reader
     * @param  bool  $more  whether posts beyond these are waiting
     */
    public function __construct(
        public Discussion $discussion,
        public array $posts,
        public array $contents,
        public int $highestNumber,
        public bool $more,
        public bool $canModerate,
    ) {}
}
