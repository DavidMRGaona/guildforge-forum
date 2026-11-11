<?php

declare(strict_types=1);

namespace Modules\Forum\Application\Services;

use Illuminate\Contracts\Auth\Authenticatable;
use Modules\Forum\Application\Queries\ModerationQueueQueryInterface;
use Modules\Forum\Application\Queries\PendingPostDTO;
use Modules\Forum\Application\Queries\ReportedPostDTO;
use Modules\Forum\Domain\Entities\Category;
use Modules\Forum\Domain\Repositories\CategoryRepositoryInterface;

/**
 * What awaits each moderator in the forum: reported posts and posts awaiting approval in the
 * categories they moderate (all of them for whoever moderates the whole forum).
 */
final readonly class ModerationQueue
{
    public const int LIMIT = 50;

    public function __construct(
        private CategoryRepositoryInterface $categories,
        private CategoryAccessPolicy $policy,
        private ModerationQueueQueryInterface $query,
    ) {}

    /**
     * Whether the person moderates anything, and so has a queue.
     */
    public function hasQueue(?Authenticatable $user): bool
    {
        return $user instanceof Authenticatable && $this->scope($user) !== [];
    }

    /**
     * @return list<ReportedPostDTO>
     */
    public function reportedPosts(Authenticatable $moderator): array
    {
        return $this->query->reportedPosts($this->scope($moderator), self::LIMIT);
    }

    /**
     * @return list<PendingPostDTO>
     */
    public function pendingPosts(Authenticatable $moderator): array
    {
        return $this->query->pendingPosts($this->scope($moderator), self::LIMIT);
    }

    /**
     * @return list<string>|null the ids of the categories they moderate; null for all of them
     */
    private function scope(Authenticatable $moderator): ?array
    {
        if ($this->policy->canModerateForum($moderator)) {
            return null;
        }

        return array_values(array_map(
            static fn (Category $category): string => $category->id()->value,
            array_filter($this->categories->all(), fn (Category $category): bool => $this->policy->canModerate($moderator, $category)),
        ));
    }
}
