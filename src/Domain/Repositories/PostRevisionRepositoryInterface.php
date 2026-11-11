<?php

declare(strict_types=1);

namespace Modules\Forum\Domain\Repositories;

use Modules\Forum\Domain\Entities\PostRevision;
use Modules\Forum\Domain\ValueObjects\PostId;

interface PostRevisionRepositoryInterface
{
    public function save(PostRevision $revision): void;

    /**
     * Every revision of the post, oldest first.
     *
     * @return list<PostRevision>
     */
    public function ofPost(PostId $postId): array;

    /**
     * The number the next revision of the post takes.
     */
    public function nextNumber(PostId $postId): int;

    public function deleteOfPost(PostId $postId): void;
}
