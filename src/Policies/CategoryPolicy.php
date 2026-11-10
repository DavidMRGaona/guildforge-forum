<?php

declare(strict_types=1);

namespace Modules\Forum\Policies;

use App\Infrastructure\Authorization\Policies\AuthorizesWithPermissions;
use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use Modules\Forum\Infrastructure\Persistence\Eloquent\Models\CategoryModel;

/**
 * Managing categories needs forum:categories.manage (admins hold every permission). The
 * categories the forum relies on are never deleted, nor those that still hold subcategories.
 */
final class CategoryPolicy
{
    use AuthorizesWithPermissions;

    public const string MANAGE = 'forum:categories.manage';

    public function viewAny(UserModel $user): bool
    {
        return $this->authorize($user, self::MANAGE);
    }

    public function view(UserModel $user, CategoryModel $category): bool
    {
        return $this->authorize($user, self::MANAGE);
    }

    public function create(UserModel $user): bool
    {
        return $this->authorize($user, self::MANAGE);
    }

    public function update(UserModel $user, CategoryModel $category): bool
    {
        return $this->authorize($user, self::MANAGE);
    }

    public function delete(UserModel $user, CategoryModel $category): bool
    {
        return $this->authorize($user, self::MANAGE)
            && $category->system_key === null
            && ! $category->children()->exists();
    }

    public function reorder(UserModel $user): bool
    {
        return $this->authorize($user, self::MANAGE);
    }
}
