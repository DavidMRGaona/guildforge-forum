<?php

declare(strict_types=1);

namespace Modules\Forum\Infrastructure\Services;

use App\Application\Publications\Contracts\PublicationRosterInterface;
use App\Application\Publications\DTOs\PublicationRosterDTO;
use App\Application\Publications\Services\PublicationSourceRegistryInterface;
use App\Infrastructure\Authorization\Services\PermissionHolderFinder;
use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Modules\Forum\Application\Services\CategoryAccessPolicy;
use Modules\Forum\Domain\Entities\Category;
use Modules\Forum\Domain\Repositories\CategoryRepositoryInterface;
use Modules\Forum\Domain\ValueObjects\CategoryId;
use Modules\Forum\Domain\ValueObjects\CategorySubject;

/**
 * The accounts that may moderate a category: whoever moderates the whole forum, the holders of
 * the roles its moderators name and part of its publication's roster, and the same for the
 * category above it. It only narrows the candidates: whether each one moderates the category
 * now is decided by CategoryAccessPolicy (roles inactive for lack of membership, suspensions),
 * so whoever notifies them checks that again when delivering.
 */
final readonly class CategoryModeratorFinder
{
    public function __construct(
        private CategoryRepositoryInterface $categories,
        private PermissionHolderFinder $holders,
        private PublicationSourceRegistryInterface $publications,
    ) {}

    /**
     * @return Collection<int, UserModel>
     */
    public function candidatesFor(CategoryId $categoryId): Collection
    {
        $candidates = $this->holders->usersWhoCan(CategoryAccessPolicy::MODERATE_FORUM)->keyBy(static fn (UserModel $user): string => strtolower((string) $user->getKey()));

        $roleIds = [];
        $accountIds = [];
        foreach ($this->chain($categoryId) as $category) {
            $moderators = $category->moderators();
            array_push($roleIds, ...$moderators->roleIds);

            $subject = $category->subject();
            if ($moderators->roster !== null && $subject instanceof CategorySubject) {
                array_push($accountIds, ...$this->rosterIds($subject));
            }
        }

        if ($roleIds !== []) {
            $holders = UserModel::query()->whereNull('anonymized_at')
                ->whereHas('roles', static fn (Builder $roles): Builder => $roles->whereIn('roles.id', array_values(array_unique($roleIds))))
                ->get();
            foreach ($holders as $holder) {
                $candidates->put(strtolower((string) $holder->getKey()), $holder);
            }
        }

        if ($accountIds !== []) {
            foreach (UserModel::query()->whereNull('anonymized_at')->whereKey(array_values(array_unique($accountIds)))->get() as $member) {
                $candidates->put(strtolower((string) $member->getKey()), $member);
            }
        }

        return $candidates->values();
    }

    /**
     * The category and the one above it.
     *
     * @return list<Category>
     */
    private function chain(CategoryId $categoryId): array
    {
        $category = $this->categories->find($categoryId);
        if (! $category instanceof Category) {
            return [];
        }

        $parentId = $category->parentId();
        $parent = $parentId instanceof CategoryId ? $this->categories->find($parentId) : null;

        return $parent instanceof Category ? [$category, $parent] : [$category];
    }

    /**
     * @return list<string>
     */
    private function rosterIds(CategorySubject $subject): array
    {
        $source = $this->publications->source($subject->type);
        $roster = $source instanceof PublicationRosterInterface ? $source->roster($subject->id) : null;

        return $roster instanceof PublicationRosterDTO ? [...$roster->memberIds, ...$roster->leaderIds] : [];
    }
}
