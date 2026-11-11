<?php

declare(strict_types=1);

namespace Modules\Forum\Tests\Support;

use Modules\Forum\Domain\Entities\Discussion;
use Modules\Forum\Domain\Repositories\DiscussionRepositoryInterface;
use Modules\Forum\Domain\ValueObjects\CategoryId;
use Modules\Forum\Domain\ValueObjects\DiscussionId;
use Modules\Forum\Infrastructure\Persistence\Eloquent\Repositories\EloquentDiscussionRepository;

/**
 * The discussions repository, except that the next number it gives is a stale one (as if
 * another discussion had just taken it) for the first calls.
 */
final class StaleNumberDiscussions implements DiscussionRepositoryInterface
{
    /**
     * @param  int  $staleCalls  how many first calls to nextNumber() answer $staleNumber
     */
    public function __construct(
        private readonly EloquentDiscussionRepository $inner,
        private int $staleCalls,
        private readonly int $staleNumber,
    ) {}

    public function nextNumber(): int
    {
        if ($this->staleCalls > 0) {
            $this->staleCalls--;

            return $this->staleNumber;
        }

        return $this->inner->nextNumber();
    }

    public function save(Discussion $discussion): void
    {
        $this->inner->save($discussion);
    }

    public function find(DiscussionId $id): ?Discussion
    {
        return $this->inner->find($id);
    }

    public function findOrFail(DiscussionId $id): Discussion
    {
        return $this->inner->findOrFail($id);
    }

    public function findByNumber(int $number): ?Discussion
    {
        return $this->inner->findByNumber($number);
    }

    public function findForUpdate(DiscussionId $id): Discussion
    {
        return $this->inner->findForUpdate($id);
    }

    public function delete(DiscussionId $id): void
    {
        $this->inner->delete($id);
    }

    public function countInCategory(CategoryId $categoryId): int
    {
        return $this->inner->countInCategory($categoryId);
    }

    public function moveAllToCategory(CategoryId $from, CategoryId $to): void
    {
        $this->inner->moveAllToCategory($from, $to);
    }

    public function replaceTopics(DiscussionId $id, array $topics): void
    {
        $this->inner->replaceTopics($id, $topics);
    }

    public function topicsOf(DiscussionId $id): array
    {
        return $this->inner->topicsOf($id);
    }
}
