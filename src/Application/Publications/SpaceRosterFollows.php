<?php

declare(strict_types=1);

namespace Modules\Forum\Application\Publications;

use App\Application\Publications\Contracts\PublicationRosterInterface;
use App\Application\Publications\DTOs\PublicationRosterDTO;
use App\Application\Publications\Services\PublicationSourceRegistryInterface;
use Carbon\CarbonImmutable;
use Modules\Forum\Application\Contracts\FollowsInterface;
use Modules\Forum\Application\Contracts\ForumNoticesInterface;
use Modules\Forum\Application\Contracts\SystemAccountInterface;
use Modules\Forum\Domain\Entities\Category;
use Modules\Forum\Domain\Enums\CategoryFollowOrigin;
use Modules\Forum\Domain\Repositories\CategoryRepositoryInterface;
use Modules\Forum\Domain\ValueObjects\AccountId;
use Modules\Forum\Domain\ValueObjects\CategorySubject;

/**
 * Keeps the follows of a campaign's space in step with its roster. The roster is read again when
 * this runs (the change only names who moved, not whether they joined or left):
 * - someone in it, while the space takes posts, follows the space and is told they can enter it,
 *   once (a follow they already had, or a second delivery of the same change, tells nothing);
 * - someone no longer in it stops following the space, unless they followed it themselves.
 * Whether they may read what follows is still decided by the space's rules when each notice is
 * delivered. The system account follows nothing.
 */
final readonly class SpaceRosterFollows
{
    public function __construct(
        private PublicationSourceRegistryInterface $publications,
        private CategoryRepositoryInterface $categories,
        private FollowsInterface $follows,
        private ForumNoticesInterface $notices,
        private SystemAccountInterface $systemAccount,
    ) {}

    /**
     * A space just created: everyone in the roster follows it and hears they can enter it.
     */
    public function welcomeRoster(string $type, string $id): void
    {
        $source = $this->publications->source($type);
        $roster = $source instanceof PublicationRosterInterface ? $source->roster($id) : null;

        if ($roster instanceof PublicationRosterDTO) {
            $this->sync($type, $id, array_values(array_unique([...$roster->memberIds, ...$roster->leaderIds])));
        }
    }

    /**
     * @param  list<string>  $accountIds  whose place in the roster changed
     */
    public function sync(string $type, string $id, array $accountIds): void
    {
        $source = $this->publications->source($type);
        if ($type !== CampaignSpaceSync::TYPE || ! $source instanceof PublicationRosterInterface || mb_strlen($id) > CategorySubject::MAX_LENGTH) {
            return;
        }

        $space = $this->categories->findBySubject(new CategorySubject($type, $id));
        if (! $space instanceof Category) {
            return;
        }

        // A source that fails throws, and the job is retried: never taken for an empty roster
        $roster = $source->roster($id) ?? new PublicationRosterDTO;
        $inRoster = array_flip([...$roster->memberIds, ...$roster->leaderIds]);
        $now = CarbonImmutable::now();

        foreach ($accountIds as $accountId) {
            $accountId = AccountId::normalize($accountId);
            if ($accountId === null || $this->systemAccount->isSystemAccount($accountId)) {
                continue;
            }

            if (! isset($inRoster[$accountId])) {
                $this->follows->unfollowCategory($accountId, $space->id(), CategoryFollowOrigin::Roster);

                continue;
            }

            if (! $space->isReadOnly() && $this->follows->followCategory($accountId, $space->id(), CategoryFollowOrigin::Roster, $now)) {
                $this->notices->addedToSpace($space->id(), $accountId);
            }
        }
    }
}
