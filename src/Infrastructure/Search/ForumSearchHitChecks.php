<?php

declare(strict_types=1);

namespace Modules\Forum\Infrastructure\Search;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Str;
use Modules\Forum\Application\Services\CategoryAccessPolicy;
use Modules\Forum\Domain\Entities\Category;
use Modules\Forum\Domain\Repositories\CategoryRepositoryInterface;
use Modules\Forum\Infrastructure\Persistence\Eloquent\Models\DiscussionModel;
use Modules\Forum\Infrastructure\Persistence\Eloquent\Models\PostModel;

/**
 * Which of the search results the person may still see, read when the results are read (ChecksSearchHits).
 *
 * The rule is the one of the documents: the discussion or reply is one the index may hold
 * (ForumSearchDocuments::discussions() and replies(), the same queries the sources use), and, in
 * addition, the category it is in now (and its parent) is readable by this person under the forum's own
 * policy, which also covers a category fed by a module that is missing. One query for the rows of the
 * batch and one for the categories, whatever the number of ids. Never catches: the core closes the type
 * when this throws.
 */
final readonly class ForumSearchHitChecks
{
    public function __construct(
        private CategoryRepositoryInterface $categories,
    ) {}

    /**
     * @param  non-empty-list<string>  $ids
     * @return list<string>
     */
    public function discussions(array $ids, ?object $user): array
    {
        $uuids = $this->uuids($ids);
        if ($uuids === [] || ! $this->mayRead($user)) {
            return [];
        }

        $rows = ForumSearchDocuments::discussions()->whereKey($uuids)->get(['id', 'category_id']);

        return $this->readable(array_values($rows->map(static fn (DiscussionModel $row): array => [$row->id, $row->category_id])->all()), $user);
    }

    /**
     * @param  non-empty-list<string>  $ids
     * @return list<string>
     */
    public function replies(array $ids, ?object $user): array
    {
        $uuids = $this->uuids($ids);
        if ($uuids === [] || ! $this->mayRead($user)) {
            return [];
        }

        // The category of each reply's discussion comes in the same query
        $rows = ForumSearchDocuments::replies()
            ->whereKey($uuids)
            ->select('forum_posts.id')
            ->addSelect(['category_id' => DiscussionModel::query()->select('category_id')->whereColumn('forum_discussions.id', 'forum_posts.discussion_id')])
            ->get();

        return $this->readable(array_values($rows->map(static fn (PostModel $row): array => [$row->id, (string) $row->getAttribute('category_id')])->all()), $user);
    }

    /**
     * @param  list<array{string, string}>  $rows  id and category id
     * @return list<string>
     */
    private function readable(array $rows, ?object $user): array
    {
        if ($rows === []) {
            return [];
        }

        $categories = [];
        // Rules that cannot be read are left out by the repository: their rows fail closed
        foreach ($this->categories->all() as $category) {
            $categories[$category->id()->value] = $category;
        }

        $policy = app(CategoryAccessPolicy::class);
        $allowed = [];
        $visible = [];
        foreach ($rows as [$id, $categoryId]) {
            $category = $categories[strtolower($categoryId)] ?? null;
            if (! $category instanceof Category) {
                continue;
            }

            $allowed[$category->id()->value] ??= $policy->canView($user instanceof Authenticatable ? $user : null, $category);
            if ($allowed[$category->id()->value]) {
                $visible[] = $id;
            }
        }

        return $visible;
    }

    /**
     * Keys are uuids: anything else matches no row, and PostgreSQL rejects it in the query.
     *
     * @param  list<string>  $ids
     * @return list<string>
     */
    private function uuids(array $ids): array
    {
        return array_values(array_unique(array_map(strtolower(...), array_filter($ids, Str::isUuid(...)))));
    }

    /**
     * A guest or an account; anything else is not a reader, and reads nothing.
     */
    private function mayRead(?object $user): bool
    {
        return $user === null || $user instanceof Authenticatable;
    }
}
