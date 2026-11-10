<?php

declare(strict_types=1);

namespace Modules\Forum\Application\Services;

use Modules\Forum\Domain\Entities\Category;
use Modules\Forum\Domain\Enums\CategoryIcon;
use Modules\Forum\Domain\Enums\SystemCategory;
use Modules\Forum\Domain\Repositories\CategoryRepositoryInterface;
use Modules\Forum\Domain\ValueObjects\CategoryAppearance;
use Modules\Forum\Domain\ValueObjects\CategoryId;
use Modules\Forum\Domain\ValueObjects\CategorySlug;

/**
 * The categories a new forum starts with: «General», «Partidas por foro» and the three the
 * automatic discussions use («Mesas», «Campañas», «Eventos»). All of them are read by anyone and
 * written in by any account. Running it again creates only what is missing: a system category by
 * its key, the others by their slug.
 */
final readonly class DefaultCategoriesInstaller
{
    private const array PLAIN = [
        'general' => CategoryIcon::Chat,
        'play_by_post' => CategoryIcon::Dice,
    ];

    private const array SYSTEM = [
        'game-tables' => CategoryIcon::Puzzle,
        'campaigns' => CategoryIcon::Map,
        'events' => CategoryIcon::Calendar,
    ];

    public function __construct(
        private CategoryRepositoryInterface $categories,
    ) {}

    public function install(): void
    {
        foreach (self::PLAIN as $key => $icon) {
            $name = __("forum::messages.defaults.{$key}.name");
            $slug = CategorySlug::fromName($name);
            if ($this->categories->findBySlug($slug) instanceof Category) {
                continue;
            }

            $category = new Category(CategoryId::generate(), $name, $slug, __("forum::messages.defaults.{$key}.description"), $this->categories->nextPosition());
            $category->restyle(new CategoryAppearance(null, $icon));
            $this->categories->save($category);
        }

        foreach (self::SYSTEM as $key => $icon) {
            $systemKey = SystemCategory::from($key);
            if ($this->categories->findBySystemKey($systemKey) instanceof Category) {
                continue;
            }

            $name = __("forum::messages.defaults.{$key}.name");
            $category = Category::system(CategoryId::generate(), $systemKey, $name, $this->freeSlug($name), $this->categories->nextPosition());
            $category->rename($name, $category->slug(), __("forum::messages.defaults.{$key}.description"));
            $category->restyle(new CategoryAppearance(null, $icon));
            $this->categories->save($category);
        }
    }

    /**
     * The name's slug, or the same with a number when a category already took it.
     */
    private function freeSlug(string $name): CategorySlug
    {
        $base = CategorySlug::fromName($name);
        $slug = $base;
        for ($n = 2; $this->categories->findBySlug($slug) instanceof Category; $n++) {
            $slug = new CategorySlug(substr($base->value, 0, CategorySlug::MAX_LENGTH - 4)."-{$n}");
        }

        return $slug;
    }
}
