<?php

declare(strict_types=1);

namespace Modules\Forum\Application\Services;

use App\Application\Authorization\Services\AuthorizationServiceInterface;
use App\Domain\Enums\UserRole;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use Modules\Forum\Application\Contracts\AccountDirectoryInterface;
use Modules\Forum\Application\Contracts\SystemAccountInterface;
use Modules\Forum\Domain\Entities\Suspension;
use Modules\Forum\Domain\Enums\ModerationAction;
use Modules\Forum\Domain\Events\ForumUserSuspended;
use Modules\Forum\Domain\Events\ForumUserSuspensionLifted;
use Modules\Forum\Domain\Exceptions\ForumActionDeniedException;
use Modules\Forum\Domain\Exceptions\InvalidModerationRecordException;
use Modules\Forum\Domain\Exceptions\ModerationRefusedException;
use Modules\Forum\Domain\Repositories\SuspensionRepositoryInterface;
use Modules\Forum\Domain\ValueObjects\AccountId;
use Modules\Forum\Domain\ValueObjects\SuspensionId;

/**
 * Suspending someone in the forum and lifting it. Only whoever moderates the whole forum does
 * either, since it reaches every category. Nobody suspends themselves or the system account,
 * and only an admin suspends someone who moderates the whole forum. Nobody lifts their own
 * suspension, and only an admin lifts one an admin made or one of a forum moderator. Someone
 * suspended moderates nothing, so they neither suspend nor lift. A new suspension of
 * someone already suspended takes the old one's place. Both are logged, and lifting one still
 * in force announces it (a suspension that ends by itself announces nothing).
 */
final readonly class SuspensionService
{
    public function __construct(
        private SuspensionRepositoryInterface $suspensions,
        private CategoryAccessPolicy $policy,
        private AccountDirectoryInterface $accounts,
        private AuthorizationServiceInterface $authorization,
        private SystemAccountInterface $systemAccount,
        private ForumSuspensions $current,
        private ModerationLogger $logger,
    ) {}

    /**
     * Whether the account holds the permission to moderate the whole forum, suspended or not.
     */
    private function holdsForumModeration(Authenticatable $user): bool
    {
        return $this->authorization->can($user, CategoryAccessPolicy::MODERATE_FORUM);
    }

    private function isAdmin(Authenticatable $user): bool
    {
        return $this->authorization->hasRole($user, UserRole::Admin->value);
    }

    public function canSuspend(?Authenticatable $moderator): bool
    {
        return $moderator instanceof Authenticatable && $this->policy->canModerateForum($moderator);
    }

    /**
     * @param  DateTimeImmutable|null  $endsAt  null: until someone lifts it
     *
     * @throws ForumActionDeniedException unless the person moderates the whole forum
     * @throws ModerationRefusedException for someone who cannot be suspended
     * @throws InvalidModerationRecordException for a blank reason or an end already past
     */
    public function suspend(Authenticatable $moderator, string $userId, string $reason, ?DateTimeImmutable $endsAt): Suspension
    {
        if (! $this->canSuspend($moderator)) {
            throw ForumActionDeniedException::because('suspend someone in the forum');
        }

        $userId = AccountId::normalize($userId) ?? throw ModerationRefusedException::notSuspendable($userId);
        $user = $this->accounts->active($userId);
        if (! $user instanceof Authenticatable
            || $userId === ForumBrowser::accountId($moderator)
            || $this->systemAccount->isSystemAccount($userId)
            // Whoever moderates the whole forum is suspended only by an admin: two moderators never suspend each other
            || ($this->holdsForumModeration($user) && ! $this->isAdmin($moderator))) {
            throw ModerationRefusedException::notSuspendable($userId);
        }

        $now = CarbonImmutable::now();
        $suspension = new Suspension(SuspensionId::generate(), $userId, ForumBrowser::accountId($moderator), $reason, $now, $endsAt);

        DB::transaction(function () use ($moderator, $suspension, $userId, $now): void {
            $replaced = $this->suspensions->activeFor($userId, $now);
            if ($replaced instanceof Suspension) {
                $replaced->lift(ForumBrowser::accountId($moderator), $now);
                $this->suspensions->save($replaced);
            }

            $this->suspensions->save($suspension);
            $this->logger->recordForAccount(ModerationAction::UserSuspended, $moderator, $userId, $suspension->reason(), [
                'ends_at' => $suspension->endsAt()?->format(DATE_ATOM),
                'replaces' => $replaced?->id()->value,
            ]);

            $event = new ForumUserSuspended($suspension->id()->value, $userId, $suspension->reason(), $suspension->endsAt());
            DB::afterCommit(static fn () => event($event));
        });

        $this->current->forget($userId);

        return $suspension;
    }

    /**
     * @throws ForumActionDeniedException unless the person moderates the whole forum
     * @throws ModerationRefusedException when there is no such suspension
     * @throws InvalidModerationRecordException when it was lifted already
     */
    public function lift(Authenticatable $moderator, SuspensionId $id, ?string $reason = null): Suspension
    {
        if (! $this->canSuspend($moderator)) {
            throw ForumActionDeniedException::because('lift a suspension');
        }

        $suspension = $this->suspensions->find($id) ?? throw ModerationRefusedException::notSuspendable($id->value);

        // Nobody lifts their own suspension, and only an admin lifts one an admin made or one of
        // someone who moderates the whole forum
        $target = $this->accounts->active($suspension->userId());
        // Fails closed: a suspender who cannot be told any more (account gone) counts as an admin
        $suspender = $suspension->suspendedBy() === null ? null : $this->accounts->active($suspension->suspendedBy());
        $suspendedByAdmin = ! $suspender instanceof Authenticatable || $this->isAdmin($suspender);
        if ($suspension->userId() === ForumBrowser::accountId($moderator)
            || (! $this->isAdmin($moderator) && ($suspendedByAdmin || ($target instanceof Authenticatable && $this->holdsForumModeration($target))))) {
            throw ForumActionDeniedException::because('lift this suspension');
        }

        DB::transaction(function () use ($moderator, $suspension, $reason): void {
            $now = CarbonImmutable::now();
            // One that ended by itself already keeps nobody out: lifting it tells nothing
            $wasInForce = $suspension->isActiveAt($now);
            $suspension->lift(ForumBrowser::accountId($moderator), $now);
            $this->suspensions->save($suspension);
            $this->logger->recordForAccount(ModerationAction::SuspensionLifted, $moderator, $suspension->userId(), $reason);

            if ($wasInForce) {
                $event = new ForumUserSuspensionLifted($suspension->id()->value, $suspension->userId(), ForumBrowser::accountId($moderator));
                DB::afterCommit(static fn () => event($event));
            }
        });

        $this->current->forget($suspension->userId());

        return $suspension;
    }
}
