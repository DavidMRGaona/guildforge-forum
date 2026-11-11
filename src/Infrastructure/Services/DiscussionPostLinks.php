<?php

declare(strict_types=1);

namespace Modules\Forum\Infrastructure\Services;

use Modules\Forum\Application\Contracts\ForumUrlsInterface;
use Modules\Forum\Application\Contracts\PostLinksInterface;
use Modules\Forum\Domain\Entities\Discussion;
use Modules\Forum\Domain\Entities\Post;

/**
 * Quotes link to the quoted post's place in its discussion. A post without a number (awaiting
 * approval) or in a discussion without one has no place to link to yet.
 */
final readonly class DiscussionPostLinks implements PostLinksInterface
{
    public function __construct(
        private ForumUrlsInterface $urls,
    ) {}

    public function urlFor(Post $post, Discussion $discussion): ?string
    {
        $number = $post->number()?->value;
        if ($number === null || $discussion->number() === null) {
            return null;
        }

        return $this->urls->discussion($discussion, $number);
    }
}
