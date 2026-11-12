<?php

declare(strict_types=1);

namespace Modules\Forum\Application\Services;

use Modules\Forum\Application\Publications\PublicationAvailability;
use Modules\Forum\Domain\Entities\Category;
use Modules\Forum\Domain\Enums\CategoryIcon;
use Modules\Forum\Domain\Enums\SystemCategory;
use Modules\Forum\Domain\Repositories\CategoryRepositoryInterface;
use Modules\Forum\Domain\ValueObjects\CategoryAppearance;
use Modules\Forum\Domain\ValueObjects\CategoryId;
use Modules\Forum\Domain\ValueObjects\CategorySlug;

/**
 * The categories a new forum starts with: «General», «Partidas por foro» and those of the automatic
 * discussions («Mesas», «Campañas», «Eventos») whose publications some module offers
 * (PublicationAvailability): without a module offering game tables there is no «Mesas» nor
 * «Campañas». All of them are read by anyone and written in by any account. Running it again
 * creates only what is missing: a system category by its key, the others by their slug.
 *
 * A system category whose source comes later (a module enabled after the forum) is created by the
 * daily reconciliation, after any module is enabled, and when a publication first needs it
 * (installSystemCategories(), systemCategory()); those never bring back the plain ones.
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
        private PublicationAvailability $availability,
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

        $this->installSystemCategories();
    }

    /**
     * Creates the system categories some source feeds and the forum lacks.
     */
    public function installSystemCategories(): void
    {
        foreach ($this->availability->systemCategories() as $key) {
            $this->systemCategory($key);
        }
    }

    /**
     * The system category, created first when its source is there and it is missing; null when no
     * module offers its publications.
     */
    public function systemCategory(SystemCategory $key): ?Category
    {
        $existing = $this->categories->findBySystemKey($key);
        if ($existing instanceof Category || ! $this->availability->offers($key->publicationType())) {
            return $existing;
        }

        $name = __("forum::messages.defaults.{$key->value}.name");
        $category = Category::system(CategoryId::generate(), $key, $name, $this->freeSlug($name), $this->categories->nextPosition());
        $category->rename($name, $category->slug(), __("forum::messages.defaults.{$key->value}.description"));
        $category->restyle(new CategoryAppearance(null, self::SYSTEM[$key->value]));
        $this->categories->save($category);

        return $category;
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
