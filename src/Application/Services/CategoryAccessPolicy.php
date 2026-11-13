<?php

declare(strict_types=1);

namespace Modules\Forum\Application\Services;

use App\Application\Authorization\Services\AssociationMembershipServiceInterface;
use App\Application\Authorization\Services\AuthorizationServiceInterface;
use App\Application\Publications\Contracts\PublicationRosterInterface;
use App\Application\Publications\DTOs\PublicationRosterDTO;
use App\Application\Publications\Services\PublicationSourceRegistryInterface;
use App\Application\Services\EmailVerificationPolicyInterface;
use App\Domain\Authorization\Repositories\RoleRepositoryInterface;
use Illuminate\Contracts\Auth\Authenticatable;
use Modules\Forum\Application\Publications\CampaignSpaceSync;
use Modules\Forum\Application\Publications\PublicationAvailability;
use Modules\Forum\Domain\Entities\Category;
use Modules\Forum\Domain\Enums\CategoryAudienceKind;
use Modules\Forum\Domain\Enums\RosterScope;
use Modules\Forum\Domain\Repositories\CategoryRepositoryInterface;
use Modules\Forum\Domain\ValueObjects\CategoryAudience;
use Modules\Forum\Domain\ValueObjects\CategoryId;
use Modules\Forum\Domain\ValueObjects\CategorySubject;
use Throwable;

/**
 * The only place that decides who reads, writes in and moderates a category. It fails closed:
 * a rule that cannot be read, a missing parent, a role, membership or roster lookup that fails
 * (reported) all mean no.
 *
 * - Reading a subcategory needs reading its parent as well.
 * - Writing needs an account that may read the category, has no email verification pending, is
 *   not barred (suspended in the forum, or a deactivated or anonymized account: ForumSuspensions), and
 *   is let in by the write rule or moderates the category. In a read-only category (or under a
 *   read-only parent) only whoever moderates the whole forum writes; its own moderators still
 *   moderate it.
 * - A category is moderated by whoever moderates the whole forum (forum:forum.moderate), by its
 *   moderators (roles, or part of its publication's roster), and by its parent's; never by
 *   someone suspended in the forum, whoever moderates the whole forum included (they still read).
 * - Whoever moderates the whole forum reads and writes everywhere, so reports can be handled in
 *   any category.
 * - A category fed by another module's publications whose source is missing (PublicationAvailability:
 *   «Mesas» or «Campañas» without a module offering game tables, a campaign's space, and their
 *   subcategories) is read by nobody else, its own moderators included: it is hidden, never deleted,
 *   and shows again when the source is back.
 *
 * Roles are matched through AuthorizationService, so roles inactive for lack of membership do not
 * count. Answers depend on the request (bound scoped): roles and rosters are read once per request.
 */
final class CategoryAccessPolicy
{
    public const string MODERATE_FORUM = 'forum:forum.moderate';

    /** @var array<string, string>|null role names by id */
    private ?array $roleNames = null;

    /** @var array<string, PublicationRosterDTO|null> */
    private array $rosters = [];

    /** @var array<string, Category|null> */
    private array $parents = [];

    public function __construct(
        private readonly CategoryRepositoryInterface $categories,
        private readonly AuthorizationServiceInterface $authorization,
        private readonly AssociationMembershipServiceInterface $membership,
        private readonly PublicationSourceRegistryInterface $publications,
        private readonly RoleRepositoryInterface $roles,
        private readonly EmailVerificationPolicyInterface $emailVerification,
        private readonly ForumSuspensions $suspensions,
        private readonly PublicationAvailability $availability,
    ) {}

    public function canView(?Authenticatable $user, Category $category): bool
    {
        return $this->decide(fn (): bool => $this->moderatesForum($user) || $this->reads($user, $category));
    }

    public function canWrite(?Authenticatable $user, Category $category): bool
    {
        return $this->decide(function () use ($user, $category): bool {
            if (! $user instanceof Authenticatable || $this->emailVerification->mustVerify($user) || $this->suspensions->isBarred($user)) {
                return false;
            }

            if ($this->moderatesForum($user)) {
                return true;
            }

            if ($category->isReadOnly() || $this->parentIsReadOnly($category)) {
                return false;
            }

            return $this->reads($user, $category)
                && ($this->matches($user, $category->writeAudience(), $category->subject()) || $this->moderatesCategory($user, $category));
        });
    }

    public function canModerate(?Authenticatable $user, Category $category): bool
    {
        // Someone suspended in the forum, or whose account is deactivated, moderates nothing, not even whoever moderates all of it
        return $this->decide(fn (): bool => $user instanceof Authenticatable
            && ! $this->suspensions->isBarred($user)
            && ($this->moderatesForum($user) || ($this->reads($user, $category) && $this->moderatesCategory($user, $category))));
    }

    /**
     * Whether anyone but the forum's moderators may see it: the sources of the publications it
     * depends on (its own and its parent's) are there. New discussions never go into one that is not.
     */
    public function isAvailable(Category $category): bool
    {
        return $this->decide(function () use ($category): bool {
            $parentId = $category->parentId();
            if (! $parentId instanceof CategoryId) {
                return $this->availability->isAvailable($category);
            }

            $parent = $this->parent($parentId);

            return $parent instanceof Category && $this->availability->isAvailable($category, $parent);
        });
    }

    /**
     * Whether the user directs the campaign a space belongs to: one of the leaders its publication
     * source names now, who still reads the space. The rule behind the characters the directors
     * share (CharacterRules); a space that is not a campaign's, or whose roster cannot be read,
     * has no directors.
     */
    public function leadsSpace(?Authenticatable $user, Category $category): bool
    {
        return $this->decide(function () use ($user, $category): bool {
            $subject = $category->subject();

            return $user instanceof Authenticatable
                && $subject instanceof CategorySubject
                && $subject->type === CampaignSpaceSync::TYPE
                && ! $this->suspensions->isBarred($user)
                && $this->reads($user, $category)
                && $this->inRoster($user, $subject, RosterScope::Leaders);
        });
    }

    /**
     * Whether the user moderates the whole forum.
     */
    public function canModerateForum(?Authenticatable $user): bool
    {
        return $this->decide(fn (): bool => $this->moderatesForum($user) && ! $this->suspensions->isBarred($user));
    }

    private function moderatesForum(?Authenticatable $user): bool
    {
        return $user instanceof Authenticatable && $this->authorization->can($user, self::MODERATE_FORUM);
    }

    private function reads(?Authenticatable $user, Category $category): bool
    {
        if (! $this->matches($user, $category->viewAudience(), $category->subject())) {
            return false;
        }

        $parentId = $category->parentId();
        if (! $parentId instanceof CategoryId) {
            return $this->availability->isAvailable($category);
        }

        $parent = $this->parent($parentId);

        // Subcategories hold no subcategories: a parent with one of its own is a broken tree
        return $parent instanceof Category
            && $parent->isTopLevel()
            && $this->matches($user, $parent->viewAudience(), $parent->subject())
            && $this->availability->isAvailable($category, $parent);
    }

    private function parentIsReadOnly(Category $category): bool
    {
        $parentId = $category->parentId();
        if (! $parentId instanceof CategoryId) {
            return false;
        }

        // A parent that cannot be read lets nobody write either
        return $this->parent($parentId)?->isReadOnly() ?? true;
    }

    private function moderatesCategory(Authenticatable $user, Category $category): bool
    {
        $moderators = $category->moderators();

        if ($moderators->roleIds !== [] && $this->holdsAnyRole($user, $moderators->roleIds)) {
            return true;
        }

        if ($moderators->roster instanceof RosterScope && $this->inRoster($user, $category->subject(), $moderators->roster)) {
            return true;
        }

        $parentId = $category->parentId();
        if (! $parentId instanceof CategoryId) {
            return false;
        }

        $parent = $this->parent($parentId);

        return $parent instanceof Category && $parent->isTopLevel() && $this->moderatesCategory($user, $parent);
    }

    private function matches(?Authenticatable $user, CategoryAudience $audience, ?CategorySubject $subject): bool
    {
        if ($audience->kind === CategoryAudienceKind::Everyone) {
            return true;
        }

        if (! $user instanceof Authenticatable) {
            return false;
        }

        return match ($audience->kind) {
            CategoryAudienceKind::SignedIn => true,
            CategoryAudienceKind::AssociationMembers => $this->membership->isAssociationMember($user),
            CategoryAudienceKind::Roles => $this->holdsAnyRole($user, $audience->roleIds),
            CategoryAudienceKind::Roster => $this->inRoster($user, $subject, RosterScope::Roster),
            CategoryAudienceKind::RosterLeaders => $this->inRoster($user, $subject, RosterScope::Leaders),
        };
    }

    /**
     * @param  list<string>  $roleIds
     */
    private function holdsAnyRole(Authenticatable $user, array $roleIds): bool
    {
        $names = $this->roleNames();

        // A deleted role has no name any more: it lets nobody in
        $wanted = array_values(array_filter(array_map(static fn (string $id): ?string => $names[$id] ?? null, $roleIds)));

        return $wanted !== [] && $this->authorization->hasAnyRole($user, $wanted);
    }

    private function inRoster(Authenticatable $user, ?CategorySubject $subject, RosterScope $scope): bool
    {
        if (! $subject instanceof CategorySubject) {
            return false;
        }

        $roster = $this->roster($subject);
        $userId = $user->getAuthIdentifier();
        if (! $roster instanceof PublicationRosterDTO || ! is_string($userId)) {
            return false;
        }

        $ids = $scope === RosterScope::Leaders ? $roster->leaderIds : [...$roster->memberIds, ...$roster->leaderIds];

        return in_array(strtolower($userId), $ids, true);
    }

    private function roster(CategorySubject $subject): ?PublicationRosterDTO
    {
        $key = "{$subject->type}:{$subject->id}";
        if (! array_key_exists($key, $this->rosters)) {
            $source = $this->publications->source($subject->type);
            $this->rosters[$key] = $source instanceof PublicationRosterInterface ? $source->roster($subject->id) : null;
        }

        return $this->rosters[$key];
    }

    /**
     * @return array<string, string>
     */
    private function roleNames(): array
    {
        if ($this->roleNames === null) {
            $names = [];
            foreach ($this->roles->findAll() as $role) {
                $names[strtolower($role->id()->value)] = $role->name()->value;
            }

            $this->roleNames = $names;
        }

        return $this->roleNames;
    }

    private function parent(CategoryId $id): ?Category
    {
        if (! array_key_exists($id->value, $this->parents)) {
            $this->parents[$id->value] = $this->categories->find($id);
        }

        return $this->parents[$id->value];
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
}
