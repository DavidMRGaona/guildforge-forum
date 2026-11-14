<?php

declare(strict_types=1);

namespace Modules\Forum\Application\Services;

use App\Application\Authorization\Services\AuthorizationServiceInterface;
use Illuminate\Contracts\Auth\Authenticatable;
use Modules\Forum\Application\Contracts\ForumSettingsInterface;
use Modules\Forum\Domain\Entities\Category;
use Modules\Forum\Domain\Entities\Character;
use Modules\Forum\Domain\Enums\CharacterAccessMode;
use Modules\Forum\Domain\Enums\CharacterBinding;
use Modules\Forum\Domain\Enums\SpaceStanding;
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
 * - A campaign's space takes characters only of its roster (players and directors), whoever
 *   moderates the whole forum included; they write there, but play nowhere they are not in
 *   (forum#108). A character tied to a space its owner left, to a campaign that is gone or to a
 *   deleted category stays tied and paused (binding()), and comes back on its own.
 * - A shared character (a PNJ) belongs to a campaign's space, not to an account: whoever directs
 *   the campaign (the publication's leaders, asked every time) creates, changes, retires and
 *   writes as it, and only there; once they stop directing they can do none of it and what they
 *   wrote stays. Posts speaking as one hide who wrote them from everyone but the space's moderators
 *   (PostContentPresenter), so one with posts is never deleted, only retired.
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
            if (! $user instanceof Authenticatable || $this->suspensions->isBarred($user)) {
                return false;
            }

            return match ($this->settings->characterAccess()) {
                CharacterAccessMode::Nobody => false,
                CharacterAccessMode::Everyone => true,
                CharacterAccessMode::Permission => $this->authorization->can($user, self::USE_PERMISSION),
            };
        });
    }

    /** Shared characters a space may hold, retired ones included */
    public const int MAX_SHARED_PER_SPACE = 30;

    /**
     * Whether the person may create shared characters in the space: they direct its campaign
     * and characters are on for them.
     */
    public function mayShareIn(?Authenticatable $user, Category $space): bool
    {
        return $user instanceof Authenticatable
            && $this->mayHaveCharacters($user)
            && $this->decide(fn (): bool => $this->policy->leadsSpace($user, $space));
    }

    /** Whether the person may list the shared characters of a space: its directors and its moderators */
    public function mayManageSharedIn(?Authenticatable $user, Category $space): bool
    {
        return $this->decide(fn (): bool => $this->policy->leadsSpace($user, $space) || $this->policy->canModerate($user, $space));
    }

    /** Whether the person directs the campaign the shared character belongs to */
    public function leads(?Authenticatable $user, Character $character): bool
    {
        return $character->isNpc() && $this->decide(function () use ($user, $character): bool {
            $space = $this->boundCategory($character);

            return $space instanceof Category && $this->policy->leadsSpace($user, $space);
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

            // The directors of a campaign moderate the shared characters of its space
            return $category instanceof Category
                && ($this->policy->canModerate($user, $category) || ($character->isNpc() && $this->policy->leadsSpace($user, $category)));
        });
    }

    /**
     * Whether a reader sees the character where a post shows it: one tied to a category only
     * whoever may read that category. Its owner and its moderators always do.
     */
    public function maySee(?Authenticatable $reader, Character $character): bool
    {
        // A post that speaks as a shared character is already read: showing its name tells nothing
        if ($character->isNpc() || ! $character->categoryId() instanceof CategoryId || $this->owns($reader, $character)) {
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
     * still readable to them. A shared one only in its space, by whoever directs the campaign.
     */
    public function usableIn(Authenticatable $user, Character $character, Category $category): bool
    {
        if ($character->isNpc()) {
            return ! $character->isRetired()
                && $character->categoryId()?->equals($category->id()) === true
                && $this->mayHaveCharacters($user)
                && $this->decide(fn (): bool => $this->policy->leadsSpace($user, $category));
        }

        if (! $this->owns($user, $character) || $character->isRetired() || ! $this->mayHaveCharacters($user)) {
            return false;
        }

        $boundId = $character->categoryId();
        if (! $boundId instanceof CategoryId) {
            return true;
        }

        $inside = $category->id()->equals($boundId) || $category->parentId()?->equals($boundId) === true;

        return $inside && $this->binding($user, $character) === CharacterBinding::Active;
    }

    /**
     * Whether the owner may use a character where it is tied (forum#108, forum#109): tied to
     * nothing, tied to a category they still read (to a campaign's space, only while in its
     * roster), or paused, and why. It fails closed: anything that cannot be read pauses it.
     */
    public function binding(Authenticatable $owner, Character $character): CharacterBinding
    {
        if (! $character->categoryId() instanceof CategoryId) {
            return CharacterBinding::Free;
        }

        try {
            $bound = $this->boundCategory($character);
            if (! $bound instanceof Category) {
                return CharacterBinding::CategoryGone;
            }

            $standing = $this->policy->spaceStanding($owner, $bound);
            if ($standing === SpaceStanding::Gone || ($standing instanceof SpaceStanding && ! $this->policy->isAvailable($bound))) {
                return CharacterBinding::CampaignGone;
            }

            if ($standing === SpaceStanding::Outside) {
                return CharacterBinding::LeftCampaign;
            }

            return $this->policy->canView($owner, $bound) ? CharacterBinding::Active : CharacterBinding::NoAccess;
        } catch (Throwable $exception) {
            report($exception);

            return CharacterBinding::NoAccess;
        }
    }

    /**
     * The categories the person may tie a character to: those they may write in, and a
     * campaign's space only while they are in its roster.
     *
     * @return list<Category>
     */
    public function bindableCategories(Authenticatable $user): array
    {
        return $this->decideList(fn (): array => array_values(array_filter(
            $this->categories->all(),
            fn (Category $category): bool => $this->policy->canWrite($user, $category)
                && in_array($this->policy->spaceStanding($user, $category), [null, SpaceStanding::Member], true),
        )));
    }

    /**
     * The category a character is tied to, if it still exists.
     */
    public function boundCategory(Character $character): ?Category
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
