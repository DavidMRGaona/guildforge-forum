<?php

declare(strict_types=1);

namespace Modules\Forum\Domain\Repositories;

use Modules\Forum\Domain\Entities\Post;
use Modules\Forum\Domain\Exceptions\PostNotFoundException;
use Modules\Forum\Domain\ValueObjects\DiscussionId;
use Modules\Forum\Domain\ValueObjects\PostId;
use Modules\Forum\Domain\ValueObjects\PostNumber;

interface PostRepositoryInterface
{
    public function save(Post $post): void;

    public function find(PostId $id): ?Post;

    /**
     * @throws PostNotFoundException
     */
    public function findOrFail(PostId $id): Post;

    /**
     * The posts that exist among the ids, in no particular order.
     *
     * @param  list<PostId>  $ids
     * @return list<Post>
     */
    public function findMany(array $ids): array;

    /**
     * The highest number given out in the discussion, whatever the post's status now; null
     * when none has one yet.
     */
    public function highestNumber(DiscussionId $discussionId): ?PostNumber;

    /**
     * Every post of the discussion: numbered ones in their order, then those awaiting approval
     * by when they were written.
     *
     * @return list<Post>
     */
    public function inDiscussion(DiscussionId $discussionId): array;

    public function delete(PostId $id): void;
}
