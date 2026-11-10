<?php

declare(strict_types=1);

namespace Modules\Forum\Domain\Repositories;

use Modules\Forum\Domain\Entities\Category;
use Modules\Forum\Domain\Enums\SystemCategory;
use Modules\Forum\Domain\Exceptions\CategoryNotFoundException;
use Modules\Forum\Domain\Exceptions\InvalidCategoryException;
use Modules\Forum\Domain\ValueObjects\CategoryId;
use Modules\Forum\Domain\ValueObjects\CategorySlug;

interface CategoryRepositoryInterface
{
    /**
     * Creates or updates the category; its counters are left as they are.
     */
    public function save(Category $category): void;

    /**
     * @throws InvalidCategoryException when its stored rules cannot be read
     */
    public function find(CategoryId $id): ?Category;

    /**
     * @throws CategoryNotFoundException
     * @throws InvalidCategoryException when its stored rules cannot be read
     */
    public function findOrFail(CategoryId $id): Category;

    /**
     * @throws InvalidCategoryException when its stored rules cannot be read
     */
    public function findBySlug(CategorySlug $slug): ?Category;

    /**
     * @throws InvalidCategoryException when its stored rules cannot be read
     */
    public function findBySystemKey(SystemCategory $key): ?Category;

    /**
     * Every category in reading order: each top-level one by position, followed by its
     * subcategories by position. A category whose stored rules cannot be read is left out (and
     * reported), so it shows to nobody.
     *
     * @return list<Category>
     */
    public function all(): array;

    /**
     * The category's subcategories by position, left out those whose rules cannot be read.
     *
     * @return list<Category>
     */
    public function childrenOf(CategoryId $id): array;

    public function hasChildren(CategoryId $id): bool;

    /**
     * The position after the last category's.
     */
    public function nextPosition(): int;

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
