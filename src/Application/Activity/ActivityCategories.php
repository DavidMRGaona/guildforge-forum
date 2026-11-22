<?php

declare(strict_types=1);

namespace Modules\Forum\Application\Activity;

use App\Domain\Search\Enums\SearchAudience;
use Modules\Forum\Application\Contracts\StoredForumAccessInterface;
use Modules\Forum\Application\Search\ForumSearchAudiences;
use Modules\Forum\Domain\Entities\Category;
use Modules\Forum\Domain\Enums\ForumAccessMode;
use Modules\Forum\Domain\Exceptions\InvalidCategoryException;
use Modules\Forum\Domain\Repositories\CategoryRepositoryInterface;
use RuntimeException;

/**
 * The categories whose posts and discussions count as activity (forum#151): those every signed-in
 * member reads, by the rule the site search indexes them with (ForumSearchAudiences): anyone or any
 * account, for a subcategory its parent too, and only while what it depends on is there. Association
 * members, roles and a campaign's roster are narrower and never count. While the forum is a preview
 * it does not exist for members, so nothing counts.
 *
 * Decided without the signed-in person (it is read in queued jobs), from what is stored now (the
 * access setting is read from its row, not from the configuration a worker booted with). What
 * cannot be read throws, so the core reads the source as failed and nothing kept is deleted: a
 * failure must never read as «does not count». The forum's own pages stay as they are, failing
 * closed.
 */
final readonly class ActivityCategories
{
    public function __construct(
        private CategoryRepositoryInterface $categories,
        private ForumSearchAudiences $audiences,
        private StoredForumAccessInterface $access,
    ) {}

    /**
     * @return list<string> ids in lower case
     *
     * @throws RuntimeException when the access setting or a publication source cannot be read
     * @throws InvalidCategoryException when the rules of a category cannot be read
     */
    public function countedIds(): array
    {
        if ($this->access->current() !== ForumAccessMode::Open) {
            return [];
        }

        $all = [];
        foreach ($this->categories->allOrFail() as $category) {
            $all[$category->id()->value] = $category;
        }

        return array_values(array_map(
            static fn (Category $category): string => $category->id()->value,
            array_filter($all, fn (Category $category): bool => in_array($this->audiences->for($category, $all, strict: true), [SearchAudience::Public, SearchAudience::Authenticated], true)),
        ));
    }
}
