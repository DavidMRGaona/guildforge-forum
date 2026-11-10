<?php

declare(strict_types=1);

namespace Modules\Forum\Domain\Repositories;

use Modules\Forum\Domain\Entities\Discussion;
use Modules\Forum\Domain\Exceptions\DiscussionNotFoundException;
use Modules\Forum\Domain\ValueObjects\CategoryId;
use Modules\Forum\Domain\ValueObjects\DiscussionId;
use Modules\Forum\Domain\ValueObjects\DiscussionTopic;

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

    public function countInCategory(CategoryId $categoryId): int;

    /**
     * Moves every discussion of one category into another. Counters are the caller's to refresh.
     */
    public function moveAllToCategory(CategoryId $from, CategoryId $to): void;

    /**
     * Replaces the discussion's tags.
     *
     * @param  list<DiscussionTopic>  $topics
     */
    public function replaceTopics(DiscussionId $id, array $topics): void;

    /**
     * The discussion's tags by label.
     *
     * @return list<DiscussionTopic>
     */
    public function topicsOf(DiscussionId $id): array;
}
