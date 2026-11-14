<?php

declare(strict_types=1);

namespace Modules\Forum\Application\Services;

use Modules\Forum\Application\Content\ReferenceLinking;
use Modules\Forum\Domain\Repositories\PostRepositoryInterface;
use Modules\Forum\Domain\ValueObjects\DiscussionId;

/**
 * Turns «#N» in a post being written into references to posts of its discussion. Whoever
 * writes already reads the discussion, so a number names a post they may read when that post
 * is visible; pending, hidden and deleted posts keep their number as text.
 */
final readonly class PostReferenceLinker
{
    public function __construct(
        private PostRepositoryInterface $posts,
    ) {}

    /**
     * Without a discussion (its first post) nothing is turned, but the editor's labels still go.
     *
     * @param  array<string, mixed>  $document  a document that passed the schema
     * @return array<string, mixed>
     */
    public function link(array $document, ?DiscussionId $discussionId): array
    {
        return ReferenceLinking::link($document, function (array $numbers) use ($discussionId): array {
            if (! $discussionId instanceof DiscussionId) {
                return [];
            }

            $ids = [];
            foreach ($this->posts->withNumbers($discussionId, $numbers) as $post) {
                $number = $post->number()?->value;
                if ($number !== null && $post->isVisible()) {
                    $ids[$number] = $post->id()->value;
                }
            }

            return $ids;
        });
    }
}
