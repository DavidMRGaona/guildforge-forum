<?php

declare(strict_types=1);

namespace Modules\Forum\Application\Activity;

use App\Domain\Search\Enums\SearchAudience;
use Modules\Forum\Application\Search\ForumSearchAudiences;
use Modules\Forum\Application\Services\ForumVisibility;
use Modules\Forum\Domain\Entities\Category;
use Modules\Forum\Domain\Repositories\CategoryRepositoryInterface;

/**
 * The categories whose posts and discussions count as activity (forum#151): those every signed-in
 * member reads, by the rule the site search indexes them with (ForumSearchAudiences): anyone or any
 * account, for a subcategory its parent too, and only while what it depends on is there. Association
 * members, roles and a campaign's roster are narrower and never count. While the forum is a preview
 * it does not exist for members, so nothing counts.
 *
 * Decided without the signed-in person (it is read in queued jobs) and failing closed: a category
 * whose rules cannot be read is left out by the repository.
 */
final readonly class ActivityCategories
{
    public function __construct(
        private CategoryRepositoryInterface $categories,
        private ForumSearchAudiences $audiences,
        private ForumVisibility $visibility,
    ) {}

    /**
     * @return list<string> ids in lower case
     */
    public function countedIds(): array
    {
        if (! $this->visibility->isOpen()) {
            return [];
        }

        $all = [];
        foreach ($this->categories->all() as $category) {
            $all[$category->id()->value] = $category;
        }

        return array_values(array_map(
            static fn (Category $category): string => $category->id()->value,
            array_filter($all, fn (Category $category): bool => in_array($this->audiences->for($category, $all), [SearchAudience::Public, SearchAudience::Authenticated], true)),
        ));
    }
}
