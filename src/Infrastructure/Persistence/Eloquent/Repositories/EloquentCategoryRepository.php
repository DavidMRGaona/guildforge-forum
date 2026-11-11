<?php

declare(strict_types=1);

namespace Modules\Forum\Infrastructure\Persistence\Eloquent\Repositories;

use Illuminate\Database\Eloquent\Builder;
use Modules\Forum\Domain\Entities\Category;
use Modules\Forum\Domain\Enums\CategoryIcon;
use Modules\Forum\Domain\Enums\ReadOnlyOrigin;
use Modules\Forum\Domain\Enums\SystemCategory;
use Modules\Forum\Domain\Exceptions\CategoryNotFoundException;
use Modules\Forum\Domain\Exceptions\InvalidCategoryException;
use Modules\Forum\Domain\Repositories\CategoryRepositoryInterface;
use Modules\Forum\Domain\ValueObjects\CategoryAppearance;
use Modules\Forum\Domain\ValueObjects\CategoryAudience;
use Modules\Forum\Domain\ValueObjects\CategoryId;
use Modules\Forum\Domain\ValueObjects\CategoryModerators;
use Modules\Forum\Domain\ValueObjects\CategorySlug;
use Modules\Forum\Domain\ValueObjects\CategorySubject;
use Modules\Forum\Infrastructure\Persistence\Eloquent\Models\CategoryModel;
use ValueError;

final class EloquentCategoryRepository implements CategoryRepositoryInterface
{
    public function save(Category $category): void
    {
        CategoryModel::query()->updateOrCreate(['id' => $category->id()->value], [
            'parent_id' => $category->parentId()?->value,
            'name' => $category->name(),
            'slug' => $category->slug()->value,
            'description' => $category->description(),
            'position' => $category->position(),
            'color' => $category->appearance()->color,
            'icon' => $category->appearance()->icon?->value,
            'system_key' => $category->systemKey()?->value,
            'view_rule' => $category->viewAudience()->toArray(),
            'write_rule' => $category->writeAudience()->toArray(),
            'moderators' => $category->moderators()->toArray(),
            'subject_type' => $category->subject()?->type,
            'subject_id' => $category->subject()?->id,
            'read_only_by' => $category->readOnlyBy()?->value,
            'approval_threshold' => $category->approvalThreshold(),
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

    public function findBySubject(CategorySubject $subject): ?Category
    {
        $model = CategoryModel::query()->where('subject_type', $subject->type)->where('subject_id', $subject->id)->orderBy('created_at')->first();

        return $model instanceof CategoryModel ? $this->toEntity($model) : null;
    }

    public function writableSubjectIds(string $subjectType): array
    {
        /** @var list<string> */
        return CategoryModel::query()
            ->where('subject_type', $subjectType)
            ->whereNotNull('subject_id')
            ->whereNull('read_only_by')
            ->orderBy('subject_id')
            ->pluck('subject_id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->all();
    }

    public function findBySystemKey(SystemCategory $key): ?Category
    {
        $model = CategoryModel::query()->where('system_key', $key->value)->first();

        return $model instanceof CategoryModel ? $this->toEntity($model) : null;
    }

    public function all(): array
    {
        $models = CategoryModel::query()->orderBy('position')->orderBy('name')->orderBy('id')->get();

        $children = [];
        foreach ($models as $model) {
            if ($model->parent_id !== null) {
                $children[$model->parent_id][] = $model;
            }
        }

        $ordered = [];
        foreach ($models as $model) {
            if ($model->parent_id !== null) {
                continue;
            }

            $ordered[] = $model;
            foreach ($children[$model->id] ?? [] as $child) {
                $ordered[] = $child;
            }
        }

        return $this->readable($ordered);
    }

    public function childrenOf(CategoryId $id): array
    {
        return $this->readable(
            CategoryModel::query()->where('parent_id', $id->value)->orderBy('position')->orderBy('name')->orderBy('id')->get()->all(),
        );
    }

    public function hasChildren(CategoryId $id): bool
    {
        return CategoryModel::query()->where('parent_id', $id->value)->exists();
    }

    public function nextPosition(): int
    {
        $highest = CategoryModel::query()->max('position');

        return is_numeric($highest) ? (int) $highest + 1 : 0;
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

    /**
     * Rows whose stored rules cannot be read are left out and reported: they show to nobody.
     *
     * @param  array<CategoryModel>  $models
     * @return list<Category>
     */
    private function readable(array $models): array
    {
        $categories = [];
        foreach ($models as $model) {
            try {
                $categories[] = $this->toEntity($model);
            } catch (InvalidCategoryException $exception) {
                report($exception);
            }
        }

        return $categories;
    }

    /**
     * @throws InvalidCategoryException when the stored rules, look or key cannot be read
     */
    private function toEntity(CategoryModel $model): Category
    {
        if ($model->view_rule === null || $model->write_rule === null) {
            throw InvalidCategoryException::unreadableRule();
        }

        try {
            $icon = $model->icon === null ? null : CategoryIcon::from($model->icon);
            $systemKey = $model->system_key === null ? null : SystemCategory::from($model->system_key);
        } catch (ValueError) {
            throw InvalidCategoryException::unreadableRule();
        }

        return new Category(
            id: CategoryId::fromString($model->id),
            name: $model->name,
            slug: new CategorySlug($model->slug),
            description: $model->description,
            position: $model->position,
            parentId: $model->parent_id === null ? null : CategoryId::fromString($model->parent_id),
            appearance: new CategoryAppearance($model->color, $icon),
            viewAudience: CategoryAudience::fromArray($model->view_rule),
            writeAudience: CategoryAudience::fromArray($model->write_rule),
            moderators: CategoryModerators::fromArray($model->moderators ?? []),
            subject: $model->subject_type === null || $model->subject_id === null ? null : new CategorySubject($model->subject_type, $model->subject_id),
            systemKey: $systemKey,
            // An origin it cannot read keeps it read-only, and never lifted by the forum
            readOnlyBy: $model->read_only_by === null ? null : (ReadOnlyOrigin::tryFrom($model->read_only_by) ?? ReadOnlyOrigin::Moderation),
            // Brought within bounds, so one stored by hand never makes the category unreadable
            approvalThreshold: max(0, min(Category::MAX_APPROVAL_THRESHOLD, (int) $model->approval_threshold)),
        );
    }

    /**
     * @param  Builder<CategoryModel>  $query
     * @return Builder<CategoryModel>
     */
    public static function inTreeOrder(Builder $query): Builder
    {
        // Top-level categories by position, each followed by its subcategories by position
        return $query
            ->orderByRaw('COALESCE((SELECT p.position FROM forum_categories p WHERE p.id = forum_categories.parent_id), forum_categories.position)')
            ->orderByRaw('COALESCE(forum_categories.parent_id, forum_categories.id)')
            ->orderByRaw('CASE WHEN forum_categories.parent_id IS NULL THEN 0 ELSE 1 END')
            ->orderBy('forum_categories.position')
            ->orderBy('forum_categories.name');
    }
}
