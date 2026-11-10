<?php

declare(strict_types=1);

namespace Modules\Forum\Domain\Repositories;

use Modules\Forum\Domain\Entities\Discussion;
use Modules\Forum\Domain\Exceptions\DiscussionNotFoundException;
use Modules\Forum\Domain\ValueObjects\DiscussionId;

interface DiscussionRepositoryInterface
{
    /**
     * Creates or updates the discussion; its counters are left as they are.
     */
    public function save(Discussion $discussion): void;

    public function find(DiscussionId $id): ?Discussion;

    /**
     * @throws DiscussionNotFoundException
     */
    public function findOrFail(DiscussionId $id): Discussion;

    /**
     * The discussion with its row locked until the surrounding transaction ends.
     *
     * @throws DiscussionNotFoundException
     */
    public function findForUpdate(DiscussionId $id): Discussion;

    /**
     * Deletes the discussion and its posts.
     */
    public function delete(DiscussionId $id): void;
}
