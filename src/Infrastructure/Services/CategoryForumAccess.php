<?php

declare(strict_types=1);

namespace Modules\Forum\Infrastructure\Services;

use Illuminate\Contracts\Auth\Authenticatable;
use Modules\Forum\Application\Contracts\ForumAccessInterface;
use Modules\Forum\Application\Services\CategoryAccessPolicy;
use Modules\Forum\Domain\Entities\Category;
use Modules\Forum\Domain\Entities\Discussion;
use Modules\Forum\Domain\Repositories\CategoryRepositoryInterface;
use Modules\Forum\Domain\ValueObjects\CategoryId;

/**
 * Who may do what in a discussion, by its category's access rules (CategoryAccessPolicy). A
 * hidden discussion is read and written only by its moderators. A discussion whose category
 * cannot be read answers no to everything. Bound per request, like the policy.
 */
final class CategoryForumAccess implements ForumAccessInterface
{
    /** @var array<string, Category|null> */
    private array $categories = [];

    public function __construct(
        private readonly CategoryRepositoryInterface $repository,
        private readonly CategoryAccessPolicy $policy,
    ) {}

    public function canRead(?Authenticatable $user, Discussion $discussion): bool
    {
        $category = $this->categoryOf($discussion);
        if (! $category instanceof Category) {
            return false;
        }

        return $discussion->isHidden() ? $this->policy->canModerate($user, $category) : $this->policy->canView($user, $category);
    }

    public function canWrite(?Authenticatable $user, Discussion $discussion): bool
    {
        $category = $this->categoryOf($discussion);
        if (! $category instanceof Category) {
            return false;
        }

        return $discussion->isHidden() ? $this->policy->canModerate($user, $category) : $this->policy->canWrite($user, $category);
    }

    public function canModerate(?Authenticatable $user, Discussion $discussion): bool
    {
        $category = $this->categoryOf($discussion);

        return $category instanceof Category && $this->policy->canModerate($user, $category);
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
