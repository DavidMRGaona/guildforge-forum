<?php

declare(strict_types=1);

namespace Modules\Forum\Infrastructure\Services;

use Illuminate\Contracts\Auth\Authenticatable;
use Modules\Forum\Application\Contracts\ForumAccessInterface;
use Modules\Forum\Application\Services\CategoryAccessPolicy;
use Modules\Forum\Application\Services\ForumVisibility;
use Modules\Forum\Domain\Entities\Category;
use Modules\Forum\Domain\Entities\Discussion;
use Modules\Forum\Domain\Repositories\CategoryRepositoryInterface;
use Modules\Forum\Domain\ValueObjects\CategoryId;

/**
 * Who may do what in a discussion, by its category's access rules (CategoryAccessPolicy). A
 * hidden discussion is read and written only by its moderators. A discussion whose category
 * cannot be read answers no to everything. One whose first post awaits approval is read only by
 * its author and its moderators. Bound per request, like the policy.
 */
final class CategoryForumAccess implements ForumAccessInterface
{
    /** @var array<string, Category|null> */
    private array $categories = [];

    public function __construct(
        private readonly CategoryRepositoryInterface $repository,
        private readonly CategoryAccessPolicy $policy,
        private readonly ForumVisibility $visibility,
    ) {}

    public function canRead(?Authenticatable $user, Discussion $discussion): bool
    {
        // A forum in preview is nothing to whoever may not see it, hidden discussions included
        $category = $this->visibility->isVisibleTo($user) ? $this->categoryOf($discussion) : null;
        if (! $category instanceof Category) {
            return false;
        }

        if ($discussion->isHidden()) {
            return $this->policy->canModerate($user, $category);
        }

        // Awaiting approval, only its author (who still reads the category) and its moderators see it
        if ($discussion->isAwaitingApproval() && ! $this->isAuthor($user, $discussion)) {
            return $this->policy->canModerate($user, $category);
        }

        return $this->policy->canView($user, $category);
    }

    public function canWrite(?Authenticatable $user, Discussion $discussion): bool
    {
        $category = $this->categoryOf($discussion);
        if (! $category instanceof Category) {
            return false;
        }

        // A hidden discussion is for its moderators, who still write only where writing is allowed
        // (not in a read-only category, not with an email verification pending)
        return $this->policy->canWrite($user, $category) && (! $discussion->isHidden() || $this->policy->canModerate($user, $category));
    }

    public function canModerate(?Authenticatable $user, Discussion $discussion): bool
    {
        $category = $this->categoryOf($discussion);

        return $category instanceof Category && $this->policy->canModerate($user, $category);
    }

    private function isAuthor(?Authenticatable $user, Discussion $discussion): bool
    {
        $id = $user?->getAuthIdentifier();

        return $discussion->authorId() !== null && is_string($id) && strtolower($id) === $discussion->authorId();
    }

    private function categoryOf(Discussion $discussion): ?Category
    {
        $id = $discussion->categoryId()->value;
        if (! array_key_exists($id, $this->categories)) {
            $this->categories[$id] = $this->repository->find(CategoryId::fromString($id));
        }

        return $this->categories[$id];
    }
}
