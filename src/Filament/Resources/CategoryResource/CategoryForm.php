<?php

declare(strict_types=1);

namespace Modules\Forum\Filament\Resources\CategoryResource;

use Illuminate\Validation\ValidationException;
use Modules\Forum\Application\Services\CategoryManager;
use Modules\Forum\Domain\Entities\Category;
use Modules\Forum\Domain\Enums\CategoryAudienceKind;
use Modules\Forum\Domain\Enums\CategoryIcon;
use Modules\Forum\Domain\Enums\RosterScope;
use Modules\Forum\Domain\Exceptions\CategoryNotFoundException;
use Modules\Forum\Domain\Exceptions\InvalidCategoryException;
use Modules\Forum\Domain\Repositories\CategoryRepositoryInterface;
use Modules\Forum\Domain\ValueObjects\CategoryAppearance;
use Modules\Forum\Domain\ValueObjects\CategoryAudience;
use Modules\Forum\Domain\ValueObjects\CategoryId;
use Modules\Forum\Domain\ValueObjects\CategoryModerators;
use Modules\Forum\Domain\ValueObjects\CategorySlug;
use Modules\Forum\Infrastructure\Persistence\Eloquent\Models\CategoryModel;

/**
 * Between the panel form and the domain: fills the form from a category and saves the form
 * through CategoryManager, so the panel never writes rules or the tree by hand. A domain refusal
 * comes back as an error next to the field it is about.
 */
final readonly class CategoryForm
{
    /** Where each refusal shows */
    private const array FIELDS = [
        'guests_cannot_write' => 'write_kind',
        'roster_without_subject' => 'view_kind',
        'own_parent' => 'parent_id',
        'parent_too_deep' => 'parent_id',
        'has_subcategories' => 'parent_id',
        'wider_than_parent' => 'view_kind',
        'narrower_than_children' => 'view_kind',
    ];

    public function __construct(
        private CategoryRepositoryInterface $categories,
        private CategoryManager $manager,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function fill(CategoryModel $record): array
    {
        $view = $this->audience($record->view_rule);
        $write = $this->audience($record->write_rule);
        $moderators = is_array($record->moderators) ? $record->moderators : [];

        return [
            'name' => $record->name,
            'slug' => $record->slug,
            'description' => $record->description,
            'parent_id' => $record->parent_id,
            'color' => $record->color,
            'icon' => $record->icon,
            'view_kind' => $view['kind'] ?? null,
            'view_role_ids' => $view['role_ids'] ?? [],
            'write_kind' => $write['kind'] ?? null,
            'write_role_ids' => $write['role_ids'] ?? [],
            'moderator_role_ids' => is_array($moderators['role_ids'] ?? null) ? $moderators['role_ids'] : [],
            'moderator_roster' => is_string($moderators['roster'] ?? null) ? $moderators['roster'] : null,
        ];
    }

    /**
     * Creates the category (at the end of the order) or updates the one with this id.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException when the domain refuses it
     */
    public function save(array $data, ?string $id): CategoryModel
    {
        try {
            $category = $id === null ? $this->newCategory($data) : $this->categories->findOrFail(CategoryId::fromString($id));

            $category->rename($this->string($data, 'name'), new CategorySlug($this->string($data, 'slug')), $this->nullableString($data, 'description'));
            $category->moveUnder($this->nullableString($data, 'parent_id') === null ? null : CategoryId::fromString($this->string($data, 'parent_id')));
            $icon = $this->nullableString($data, 'icon');
            $category->restyle(new CategoryAppearance($this->nullableString($data, 'color'), $icon === null ? null : CategoryIcon::from($icon)));
            $roster = $this->nullableString($data, 'moderator_roster');
            $category->changeAccess(
                $this->audienceFrom($data, 'view'),
                $this->audienceFrom($data, 'write'),
                new CategoryModerators($this->list($data, 'moderator_role_ids'), $roster === null ? null : RosterScope::from($roster)),
            );

            $this->manager->save($category);
        } catch (InvalidCategoryException $exception) {
            throw ValidationException::withMessages([
                'data.'.(self::FIELDS[$exception->reason] ?? 'name') => __("forum::messages.categories.errors.{$exception->reason}"),
            ]);
        } catch (CategoryNotFoundException) {
            throw ValidationException::withMessages(['data.parent_id' => __('forum::messages.categories.errors.missing_parent')]);
        }

        return CategoryModel::query()->findOrFail($category->id()->value);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function newCategory(array $data): Category
    {
        return new Category(
            CategoryId::generate(),
            $this->string($data, 'name'),
            new CategorySlug($this->string($data, 'slug')),
            position: $this->categories->nextPosition(),
        );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function audienceFrom(array $data, string $prefix): CategoryAudience
    {
        $kind = CategoryAudienceKind::from($this->string($data, "{$prefix}_kind"));

        return new CategoryAudience($kind, $kind === CategoryAudienceKind::Roles ? $this->list($data, "{$prefix}_role_ids") : []);
    }

    /**
     * @return array<mixed>|null
     */
    private function audience(mixed $rule): ?array
    {
        return is_array($rule) ? $rule : null;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function string(array $data, string $key): string
    {
        $value = $data[$key] ?? '';

        return is_scalar($value) ? (string) $value : '';
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function nullableString(array $data, string $key): ?string
    {
        $value = $this->string($data, $key);

        return trim($value) === '' ? null : $value;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<mixed>
     */
    private function list(array $data, string $key): array
    {
        $value = $data[$key] ?? [];

        return is_array($value) ? array_values($value) : [];
    }
}
