<?php

declare(strict_types=1);

namespace Modules\Forum\Application\Services;

use App\Application\Authorization\Services\AuthorizationServiceInterface;
use Illuminate\Contracts\Auth\Authenticatable;
use Modules\Forum\Application\Contracts\ForumSettingsInterface;
use Modules\Forum\Domain\Entities\Category;
use Modules\Forum\Domain\Entities\Character;
use Modules\Forum\Domain\Enums\CharacterAccessMode;
use Modules\Forum\Domain\Repositories\CategoryRepositoryInterface;
use Modules\Forum\Domain\ValueObjects\CategoryId;
use Throwable;

/**
 * The only place that decides what anyone may do with characters. It fails closed: a setting,
 * permission or category that cannot be read means no.
 *
 * - Having characters (creating, changing and bringing them back, writing as them) follows the
 *   forum setting: nobody, any signed-in account, or only with forum:characters.use. Someone
 *   suspended in the forum may not. Their owner may always list, retire and delete their own.
 * - A character tied to a category is offered only in it and in its subcategories, and only
 *   whoever reads that category sees it (its posts show their account to anyone else).
 * - Characters are moderated by whoever moderates the whole forum and, for a character tied to
 *   a category, by whoever moderates that category (CategoryAccessPolicy).
 */
final readonly class CharacterRules
{
    public const string USE_PERMISSION = 'forum:characters.use';

    public function __construct(
        private ForumSettingsInterface $settings,
        private AuthorizationServiceInterface $authorization,
        private CategoryAccessPolicy $policy,
        private CategoryRepositoryInterface $categories,
        private ForumSuspensions $suspensions,
    ) {}

    /** Whether the person may create characters and write as them */
    public function mayHaveCharacters(?Authenticatable $user): bool
    {
        return $this->decide(function () use ($user): bool {
            if (! $user instanceof Authenticatable || $this->suspensions->isSuspended($user)) {
                return false;
            }

            return match ($this->settings->characterAccess()) {
                CharacterAccessMode::Nobody => false,
                CharacterAccessMode::Everyone => true,
                CharacterAccessMode::Permission => $this->authorization->can($user, self::USE_PERMISSION),
            };
        });
    }

    public function owns(?Authenticatable $user, Character $character): bool
    {
        return $user instanceof Authenticatable && $character->isOwnedBy((string) ForumBrowser::accountId($user));
    }

    /** Whether the person moderates the character: the whole forum, or the category it is tied to */
    public function moderates(?Authenticatable $user, Character $character): bool
    {
        return $this->decide(function () use ($user, $character): bool {
            if ($this->policy->canModerateForum($user)) {
                return true;
            }

            $category = $this->boundCategory($character);

            return $category instanceof Category && $this->policy->canModerate($user, $category);
        });
    }

    /**
     * Whether a reader sees the character where a post shows it: one tied to a category only
     * whoever may read that category. Its owner and its moderators always do.
     */
    public function maySee(?Authenticatable $reader, Character $character): bool
    {
        if (! $character->categoryId() instanceof CategoryId || $this->owns($reader, $character)) {
            return true;
        }

        return $this->decide(function () use ($reader, $character): bool {
            $category = $this->boundCategory($character);

            return $category instanceof Category && $this->policy->canView($reader, $category);
        });
    }

    /**
     * Whether the person may write as the character in a category: their own, not retired,
     * characters on for them, and tied to no category or to that one (or to its parent) and
     * still readable to them.
     */
    public function usableIn(Authenticatable $user, Character $character, Category $category): bool
    {
        if (! $this->owns($user, $character) || $character->isRetired() || ! $this->mayHaveCharacters($user)) {
            return false;
        }

        $boundId = $character->categoryId();
        if (! $boundId instanceof CategoryId) {
            return true;
        }

        $inside = $category->id()->equals($boundId) || $category->parentId()?->equals($boundId) === true;

        return $inside && $this->decide(function () use ($user, $character): bool {
            $bound = $this->boundCategory($character);

            return $bound instanceof Category && $this->policy->canView($user, $bound);
        });
    }

    /**
     * The categories the person may tie a character to: those they may write in.
     *
     * @return list<Category>
     */
    public function bindableCategories(Authenticatable $user): array
    {
        return $this->decideList(fn (): array => array_values(array_filter(
            $this->categories->all(),
            fn (Category $category): bool => $this->policy->canWrite($user, $category),
        )));
    }

    private function boundCategory(Character $character): ?Category
    {
        $id = $character->categoryId();

        return $id instanceof CategoryId ? $this->categories->find($id) : null;
    }

    /**
     * @param  callable(): bool  $decision
     */
    private function decide(callable $decision): bool
    {
        try {
            return $decision();
        } catch (Throwable $exception) {
            report($exception);

            return false;
        }
    }

    /**
     * @param  callable(): list<Category>  $decision
     * @return list<Category>
     */
    private function decideList(callable $decision): array
    {
        try {
            return $decision();
        } catch (Throwable $exception) {
            report($exception);

            return [];
        }
    }
}
