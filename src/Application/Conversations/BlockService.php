<?php

declare(strict_types=1);

namespace Modules\Forum\Application\Conversations;

use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Contracts\Auth\Authenticatable;
use Modules\Forum\Application\Contracts\AccountDirectoryInterface;
use Modules\Forum\Application\Contracts\SystemAccountInterface;
use Modules\Forum\Application\Contracts\UserBlocksInterface;
use Modules\Forum\Application\Services\ForumBrowser;
use Modules\Forum\Domain\Exceptions\ConversationRefusedException;
use Modules\Forum\Domain\ValueObjects\AccountId;

/**
 * Blocking people and unblocking them. Anyone signed in blocks any other existing account
 * (suspended or not): it is the blocker's own choice, and nobody is told. Conversations they
 * already share stay; the blocked person just cannot write in them any more.
 */
final readonly class BlockService
{
    public function __construct(
        private UserBlocksInterface $blocks,
        private AccountDirectoryInterface $accounts,
        private SystemAccountInterface $systemAccount,
    ) {}

    /**
     * @throws ConversationRefusedException for oneself, the forum's account or an account that does not exist
     */
    public function block(Authenticatable $user, string $accountId): void
    {
        $blocker = (string) ForumBrowser::accountId($user);
        $blocked = AccountId::normalize($accountId);
        if ($blocked === null || $blocked === $blocker || $this->systemAccount->isSystemAccount($blocked) || $this->accounts->namesOf([$blocked]) === []) {
            throw ConversationRefusedException::because(ConversationRefusedException::NOT_ALLOWED);
        }

        $this->blocks->block($blocker, $blocked, CarbonImmutable::now()->toDateTimeImmutable());
    }

    public function unblock(Authenticatable $user, string $accountId): void
    {
        $this->blocks->unblock((string) ForumBrowser::accountId($user), $accountId);
    }

    public function hasBlocked(Authenticatable $user, string $accountId): bool
    {
        return $this->blocks->blockersOf($accountId, [(string) ForumBrowser::accountId($user)]) !== [];
    }

    /**
     * The people the person blocked, with the names they show now.
     *
     * @return list<array{id: string, name: string, at: DateTimeImmutable}>
     */
    public function blockedBy(Authenticatable $user): array
    {
        $blocks = $this->blocks->blockedBy((string) ForumBrowser::accountId($user));
        $names = $this->accounts->namesOf(array_column($blocks, 'id'));

        $list = [];
        foreach ($blocks as $block) {
            if (isset($names[$block['id']])) {
                $list[] = ['id' => $block['id'], 'name' => $names[$block['id']], 'at' => $block['at']];
            }
        }

        return $list;
    }
}
