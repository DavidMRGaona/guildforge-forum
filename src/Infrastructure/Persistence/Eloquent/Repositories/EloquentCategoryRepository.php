<?php

declare(strict_types=1);

namespace Modules\Forum\Infrastructure\Persistence\Eloquent\Repositories;

use Modules\Forum\Domain\Entities\Category;
use Modules\Forum\Domain\Exceptions\CategoryNotFoundException;
use Modules\Forum\Domain\Repositories\CategoryRepositoryInterface;
use Modules\Forum\Domain\ValueObjects\CategoryId;
use Modules\Forum\Domain\ValueObjects\CategorySlug;
use Modules\Forum\Infrastructure\Persistence\Eloquent\Models\CategoryModel;

final class EloquentCategoryRepository implements CategoryRepositoryInterface
{
    public function save(Category $category): void
    {
        CategoryModel::query()->updateOrCreate(['id' => $category->id()->value], [
            'name' => $category->name(),
            'slug' => $category->slug()->value,
            'description' => $category->description(),
            'position' => $category->position(),
        ]);
    }

    public function find(CategoryId $id): ?Category
    {
        $model = CategoryModel::query()->find($id->value);

        return $model instanceof CategoryModel ? $this->toEntity($model) : null;
    }

    public function findOrFail(CategoryId $id): Category
    {
        return $this->find($id) ?? throw CategoryNotFoundException::withId($id->value);
    }

    public function findBySlug(CategorySlug $slug): ?Category
    {
        $model = CategoryModel::query()->where('slug', $slug->value)->first();

        return $model instanceof CategoryModel ? $this->toEntity($model) : null;
    }

    public function lockForWrite(CategoryId $id): void
    {
        if (! CategoryModel::query()->whereKey($id->value)->lockForUpdate()->exists()) {
            throw CategoryNotFoundException::withId($id->value);
        }
    }

    public function delete(CategoryId $id): void
    {
        CategoryModel::query()->whereKey($id->value)->delete();
    }

    private function toEntity(CategoryModel $model): Category
    {
        return new Category(
            id: CategoryId::fromString($model->id),
            name: $model->name,
            slug: new CategorySlug($model->slug),
            description: $model->description,
            position: $model->position,
        );
    }
}
