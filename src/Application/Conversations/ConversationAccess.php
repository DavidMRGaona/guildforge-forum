<?php

declare(strict_types=1);

namespace Modules\Forum\Application\Conversations;

use Illuminate\Contracts\Auth\Authenticatable;
use Modules\Forum\Application\Contracts\AccountDirectoryInterface;
use Modules\Forum\Application\Contracts\ConversationParticipantsInterface;
use Modules\Forum\Application\Contracts\SystemAccountInterface;
use Modules\Forum\Application\Contracts\UserBlocksInterface;
use Modules\Forum\Application\Services\ForumBrowser;
use Modules\Forum\Application\Services\ForumSuspensions;
use Modules\Forum\Domain\Entities\Conversation;
use Modules\Forum\Domain\Exceptions\ConversationRefusedException;
use Modules\Forum\Domain\ValueObjects\ConversationId;
use Throwable;

/**
 * Who reads and who writes in private conversations. Only the people in a conversation read it:
 * nobody else, not whoever moderates the forum, not the site's administration. Everything here
 * fails closed: when the participants, the blocks or the account cannot be read, the answer is
 * no.
 *
 * canRead() is the one answer to «is this account in this conversation?»: pages, endpoints,
 * reports, notices and the private channel of a conversation (forum#17) all ask it here.
 *
 * Writing (and adding people) also needs the person not to be barred (suspended in the forum,
 * deactivated or anonymized), somebody else left to write to, and nobody in the conversation
 * who blocked them.
 */
final readonly class ConversationAccess
{
    public function __construct(
        private ConversationParticipantsInterface $participants,
        private UserBlocksInterface $blocks,
        private ForumSuspensions $suspensions,
        private AccountDirectoryInterface $accounts,
        private SystemAccountInterface $systemAccount,
    ) {}

    /**
     * Whether the person is in the conversation now.
     */
    public function canRead(?Authenticatable $user, ConversationId $id): bool
    {
        $accountId = ForumBrowser::accountId($user);
        if ($accountId === null) {
            return false;
        }

        try {
            return $this->participants->isIn($id, $accountId);
        } catch (Throwable $exception) {
            report($exception);

            return false;
        }
    }

    /**
     * Why the person may not write in the conversation, or null when they may.
     *
     * @param  list<string>|null  $participants  who is in it, when the caller read it already
     */
    public function writeRefusal(Authenticatable $user, Conversation $conversation, ?array $participants = null): ?string
    {
        $accountId = ForumBrowser::accountId($user);
        if ($accountId === null || $this->suspensions->isBarred($user)) {
            return ConversationRefusedException::BARRED;
        }

        try {
            $participants ??= $this->participants->of($conversation->id());
            if (! in_array($accountId, $participants, true)) {
                return ConversationRefusedException::CANNOT_WRITE;
            }

            $others = array_values(array_diff($participants, [$accountId]));
            if ($others === []) {
                return ConversationRefusedException::ALONE;
            }

            return $this->blocks->blockersOf($accountId, $others) === [] ? null : ConversationRefusedException::CANNOT_WRITE;
        } catch (Throwable $exception) {
            report($exception);

            return ConversationRefusedException::CANNOT_WRITE;
        }
    }

    /**
     * Whether the account may be in a conversation: an active account that is not the forum's
     * own and not suspended in the forum.
     */
    public function mayTakePart(string $accountId): bool
    {
        try {
            if ($this->systemAccount->isSystemAccount($accountId)) {
                return false;
            }

            $account = $this->accounts->active($accountId);

            return $account instanceof Authenticatable && ! $this->suspensions->isBarred($account);
        } catch (Throwable $exception) {
            report($exception);

            return false;
        }
    }

    /**
     * Whether the person may not reach the account at all: it blocked them.
     */
    public function isBlockedBy(string $accountId, string $byAccountId): bool
    {
        try {
            return $this->blocks->blockersOf($accountId, [$byAccountId]) !== [];
        } catch (Throwable $exception) {
            report($exception);

            return true;
        }
    }
}
