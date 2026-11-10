<?php

declare(strict_types=1);

namespace Modules\Forum\Domain\Repositories;

use Modules\Forum\Domain\Entities\Category;
use Modules\Forum\Domain\Exceptions\CategoryNotFoundException;
use Modules\Forum\Domain\ValueObjects\CategoryId;
use Modules\Forum\Domain\ValueObjects\CategorySlug;

interface CategoryRepositoryInterface
{
    /**
     * Creates or updates the category; its counters are left as they are.
     */
    public function save(Category $category): void;

    public function find(CategoryId $id): ?Category;

    /**
     * @throws CategoryNotFoundException
     */
    public function findOrFail(CategoryId $id): Category;

    public function findBySlug(CategorySlug $slug): ?Category;

    /**
     * Locks the category's row until the surrounding transaction ends. Every write that changes
     * what a category counts locks it first, then its discussion.
     *
     * @throws CategoryNotFoundException
     */
    public function lockForWrite(CategoryId $id): void;

    /**
     * Fails while the category still holds discussions or subcategories.
     */
    public function delete(CategoryId $id): void;
}
