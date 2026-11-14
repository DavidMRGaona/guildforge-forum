<?php

declare(strict_types=1);

namespace Modules\Forum\Application\Services;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use Modules\Forum\Application\Contracts\AccountDirectoryInterface;
use Modules\Forum\Application\Contracts\SystemAccountInterface;
use Modules\Forum\Domain\Enums\ModerationAction;
use Modules\Forum\Domain\Exceptions\ForumActionDeniedException;
use Modules\Forum\Domain\Exceptions\InvalidModerationRecordException;
use Modules\Forum\Domain\Exceptions\ModerationRefusedException;
use Modules\Forum\Domain\Repositories\MemberTitleRepositoryInterface;
use Modules\Forum\Domain\ValueObjects\AccountId;
use Modules\Forum\Domain\ValueObjects\MemberTitle;

/**
 * The titles whoever moderates the whole forum gives people (forum#121): a decorative line next
 * to their name, set, changed or removed from the post menu, every change in the moderation log.
 * Whoever moderates only some categories gives none, since the title shows in the whole forum.
 */
final readonly class MemberTitleService
{
    public function __construct(
        private MemberTitleRepositoryInterface $titles,
        private CategoryAccessPolicy $policy,
        private AccountDirectoryInterface $accounts,
        private SystemAccountInterface $systemAccount,
        private ModerationLogger $logger,
    ) {}

    public function canChange(?Authenticatable $moderator): bool
    {
        return $moderator instanceof Authenticatable && $this->policy->canModerateForum($moderator);
    }

    /**
     * Sets the person's title, or removes it for a blank one; nothing is written when it does not change.
     *
     * @return MemberTitle|null the title the person holds now
     *
     * @throws ForumActionDeniedException unless the person moderates the whole forum
     * @throws ModerationRefusedException for an account that is not active, or the forum's own
     * @throws InvalidModerationRecordException for a title too long or of more than one line
     */
    public function change(Authenticatable $moderator, string $userId, ?string $title): ?MemberTitle
    {
        if (! $this->canChange($moderator)) {
            throw ForumActionDeniedException::because('give someone a forum title');
        }

        $userId = AccountId::normalize($userId) ?? throw ModerationRefusedException::notTitleable($userId);
        if (! $this->accounts->active($userId) instanceof Authenticatable || $this->systemAccount->isSystemAccount($userId)) {
            throw ModerationRefusedException::notTitleable($userId);
        }

        $wanted = MemberTitle::fromInput($title);
        $moderatorId = ForumBrowser::accountId($moderator) ?? throw ForumActionDeniedException::because('give someone a forum title');

        return DB::transaction(function () use ($moderator, $moderatorId, $userId, $wanted): ?MemberTitle {
            $current = $this->titles->find($userId);
            if ($current?->value === $wanted?->value) {
                return $current;
            }

            if ($wanted instanceof MemberTitle) {
                $this->titles->save($userId, $wanted, $moderatorId, CarbonImmutable::now());
            } else {
                $this->titles->forget($userId);
            }
            $this->logger->recordForAccount(ModerationAction::TitleChanged, $moderator, $userId, null, [
                'from' => $current?->value,
                'to' => $wanted?->value,
            ]);

            return $wanted;
        });
    }
}
