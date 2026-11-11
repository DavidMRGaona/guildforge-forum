<?php

declare(strict_types=1);

namespace Modules\Forum\Application\Services;

use DateTimeImmutable;
use Illuminate\Contracts\Auth\Authenticatable;
use Modules\Forum\Application\Contracts\DiscussionReadsInterface;
use Modules\Forum\Application\Contracts\ForumSettingsInterface;
use Modules\Forum\Application\DTOs\CategoryAccessDTO;
use Modules\Forum\Application\Queries\DiscussionCursor;
use Modules\Forum\Application\Queries\DiscussionListCriteria;
use Modules\Forum\Application\Queries\DiscussionListPageDTO;
use Modules\Forum\Application\Queries\DiscussionListQueryInterface;
use Modules\Forum\Domain\Entities\Category;
use Modules\Forum\Domain\Repositories\CategoryRepositoryInterface;
use Modules\Forum\Domain\ValueObjects\CategoryId;
use Modules\Forum\Domain\ValueObjects\DiscussionTopic;

/**
 * What a reader finds in the forum: the categories they may read and the discussions in them.
 * Every list starts from the categories the access rules let the reader see, so nothing else
 * can slip in through a filter.
 */
final readonly class ForumBrowser
{
    public function __construct(
        private CategoryRepositoryInterface $categories,
        private CategoryAccessPolicy $policy,
        private DiscussionListQueryInterface $lists,
        private DiscussionReadsInterface $reads,
        private ForumSettingsInterface $settings,
    ) {}

    /**
     * The categories the reader may read, in reading order (each top-level one followed by its
     * subcategories).
     *
     * @return list<CategoryAccessDTO>
     */
    public function readableCategories(?Authenticatable $reader): array
    {
        $readable = [];
        foreach ($this->categories->all() as $category) {
            if (! $this->policy->canView($reader, $category)) {
                continue;
            }

            $readable[] = new CategoryAccessDTO(
                category: $category,
                canWrite: $this->policy->canWrite($reader, $category),
                canModerate: $this->policy->canModerate($reader, $category),
                guestsRead: $reader === null || $this->policy->canView(null, $category),
            );
        }

        return $readable;
    }

    /**
     * The readable category with the slug, or null when it does not exist or the reader may
     * not read it.
     *
     * @param  list<CategoryAccessDTO>  $readable
     */
    public function findReadable(array $readable, string $slug): ?CategoryAccessDTO
    {
        foreach ($readable as $access) {
            if ($access->category->slug()->value === $slug) {
                return $access;
            }
        }

        return null;
    }

    /**
     * The category and its readable subcategories.
     *
     * @param  list<CategoryAccessDTO>  $readable
     * @return list<CategoryAccessDTO>
     */
    public function withSubcategories(array $readable, CategoryAccessDTO $category): array
    {
        $id = $category->category->id();

        return array_values(array_filter(
            $readable,
            static fn (CategoryAccessDTO $access): bool => $access->category->id()->equals($id)
                || ($access->category->parentId() instanceof CategoryId && $access->category->parentId()->equals($id)),
        ));
    }

    /**
     * A page of the discussions in the categories, by last activity.
     *
     * @param  list<CategoryAccessDTO>  $scope  categories the reader may read
     */
    public function discussions(?Authenticatable $reader, array $scope, ?string $topicKey, bool $onlyUnread, ?DiscussionCursor $after, bool $pinnedFirst): DiscussionListPageDTO
    {
        $readerId = self::accountId($reader);

        return $this->lists->page(new DiscussionListCriteria(
            categoryIds: array_map(static fn (CategoryAccessDTO $access): string => $access->category->id()->value, $scope),
            moderatedCategoryIds: array_values(array_map(
                static fn (CategoryAccessDTO $access): string => $access->category->id()->value,
                array_filter($scope, static fn (CategoryAccessDTO $access): bool => $access->canModerate),
            )),
            limit: $this->settings->discussionsPerPage(),
            readerId: $readerId,
            onlyUnread: $onlyUnread && $readerId !== null,
            topicKey: $topicKey,
            pinnedFirst: $pinnedFirst,
            after: $after,
        ));
    }

    /**
     * The game-system tags found in the categories, for the list filter.
     *
     * @param  list<CategoryAccessDTO>  $scope
     * @return list<DiscussionTopic>
     */
    public function topics(array $scope): array
    {
        return $this->lists->topicsIn(array_map(static fn (CategoryAccessDTO $access): string => $access->category->id()->value, $scope));
    }

    /**
     * Marks every discussion of the categories as read, up to its last visible post.
     *
     * @param  list<CategoryAccessDTO>  $scope  categories the reader may read
     */
    public function markRead(Authenticatable $reader, array $scope, DateTimeImmutable $at): void
    {
        $readerId = self::accountId($reader);
        if ($readerId === null) {
            return;
        }

        $this->reads->markCategoriesRead(
            $readerId,
            array_map(static fn (CategoryAccessDTO $access): CategoryId => $access->category->id(), $scope),
            $at,
        );
    }

    public static function accountId(?Authenticatable $user): ?string
    {
        if (! $user instanceof Authenticatable) {
            return null;
        }

        $id = $user->getAuthIdentifier();

        return is_scalar($id) && (string) $id !== '' ? strtolower((string) $id) : null;
    }

    /**
     * @param  list<CategoryAccessDTO>  $readable
     */
    public static function categoryById(array $readable, string $id): ?Category
    {
        foreach ($readable as $access) {
            if ($access->category->id()->value === $id) {
                return $access->category;
            }
        }

        return null;
    }
}
