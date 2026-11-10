<?php

declare(strict_types=1);

namespace Modules\Forum\Application\Services;

use Illuminate\Support\Facades\DB;
use Modules\Forum\Domain\Entities\Category;
use Modules\Forum\Domain\Exceptions\CategoryNotFoundException;
use Modules\Forum\Domain\Exceptions\InvalidCategoryException;
use Modules\Forum\Domain\Repositories\CategoryRepositoryInterface;
use Modules\Forum\Domain\Repositories\DiscussionRepositoryInterface;
use Modules\Forum\Domain\ValueObjects\CategoryId;

/**
 * Saves and deletes categories, keeping the tree sound: two levels at most, and a subcategory
 * never readable by more people than its parent (checked from both sides when either is saved).
 * Reading a subcategory needs reading its parent anyway (CategoryAccessPolicy); this check
 * refuses rules that would only look wider.
 */
final readonly class CategoryManager
{
    public function __construct(
        private CategoryRepositoryInterface $categories,
        private DiscussionRepositoryInterface $discussions,
        private ForumWriter $writer,
    ) {}

    /**
     * @throws InvalidCategoryException when the tree or its rules would break
     * @throws CategoryNotFoundException when its parent does not exist
     */
    public function save(Category $category): void
    {
        DB::transaction(function () use ($category): void {
            $parentId = $category->parentId();
            if ($parentId instanceof CategoryId) {
                $parent = $this->categories->findOrFail($parentId);

                if (! $parent->isTopLevel()) {
                    throw InvalidCategoryException::parentTooDeep($parent->id()->value);
                }

                if ($this->categories->hasChildren($category->id())) {
                    throw InvalidCategoryException::hasSubcategories($category->id()->value);
                }

                if (! $category->viewAudience()->isWithin($parent->viewAudience())) {
                    throw InvalidCategoryException::widerThanParent($category->id()->value);
                }
            }

            foreach ($this->categories->childrenOf($category->id()) as $child) {
                if (! $child->viewAudience()->isWithin($category->viewAudience())) {
                    throw InvalidCategoryException::narrowerThanChildren($category->id()->value);
                }
            }

            $this->categories->save($category);
        });
    }

    /**
     * Deletes the category after moving its discussions into another one. Categories the forum
     * relies on, and categories that still hold subcategories, are never deleted.
     *
     * @param  CategoryId|null  $moveDiscussionsTo  required while it holds discussions
     *
     * @throws InvalidCategoryException
     * @throws CategoryNotFoundException
     */
    public function delete(CategoryId $id, ?CategoryId $moveDiscussionsTo): void
    {
        DB::transaction(function () use ($id, $moveDiscussionsTo): void {
            $category = $this->categories->findOrFail($id);

            if ($category->isSystem()) {
                throw InvalidCategoryException::systemCategory($id->value);
            }

            if ($this->categories->hasChildren($id)) {
                throw InvalidCategoryException::stillHoldsSubcategories($id->value);
            }

            if ($this->discussions->countInCategory($id) > 0) {
                if (! $moveDiscussionsTo instanceof CategoryId) {
                    throw InvalidCategoryException::stillHoldsDiscussions($id->value);
                }

                if ($moveDiscussionsTo->equals($id)) {
                    throw InvalidCategoryException::cannotMoveIntoItself($id->value);
                }

                $this->categories->findOrFail($moveDiscussionsTo);
                $this->writer->moveAllDiscussions($id, $moveDiscussionsTo);
            }

            $this->categories->delete($id);
        });
    }
}
