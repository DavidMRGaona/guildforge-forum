<?php

declare(strict_types=1);

namespace Modules\Forum\Application\Contracts;

use DateTimeImmutable;
use Modules\Forum\Application\DTOs\PostLikesDTO;
use Modules\Forum\Domain\ValueObjects\PostId;

/**
 * The likes of posts: one per person and post. Who may like what is decided before calling it
 * (PostLikeService).
 */
interface PostLikesInterface
{
    /**
     * Adds the person's like; false when they had already liked the post.
     */
    public function add(PostId $postId, string $accountId, DateTimeImmutable $at): bool;

    /**
     * Takes the person's like back; false when there was none.
     */
    public function remove(PostId $postId, string $accountId): bool;

    /**
     * Each post's count, whether the viewer liked it and who liked it most recently (at most
     * $names accounts per post). Posts without likes are left out.
     *
     * @param  list<PostId>  $postIds
     * @return array<string, PostLikesDTO> by post id
     */
    public function of(array $postIds, ?string $viewerId, int $names): array;
}
