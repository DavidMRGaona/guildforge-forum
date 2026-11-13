<?php

declare(strict_types=1);

namespace Modules\Forum\Application\Contracts;

use DateTimeImmutable;
use Modules\Forum\Domain\ValueObjects\ConversationId;

/**
 * Who is in each private conversation now, and how far each of them read. Leaving takes the
 * row away: someone who left is not in the conversation, and reads nothing of it.
 */
interface ConversationParticipantsInterface
{
    /**
     * The ids of the accounts in the conversation, lowercase.
     *
     * @return list<string>
     */
    public function of(ConversationId $id): array;

    public function isIn(ConversationId $id, string $accountId): bool;

    /**
     * Adds the person, read through the given entry. Adding someone already in changes nothing.
     */
    public function add(ConversationId $id, string $accountId, int $readThrough, DateTimeImmutable $at): void;

    public function remove(ConversationId $id, string $accountId): void;

    /**
     * The last entry the person read, or null when they are not in the conversation.
     */
    public function readThrough(ConversationId $id, string $accountId): ?int;

    /**
     * Marks the conversation read through the entry; it never goes back.
     */
    public function markRead(ConversationId $id, string $accountId, int $number): void;

    /**
     * Claims the mail about a new message for the person: true for the first message after the
     * last one they read, false when a mail went out already and they have not read since.
     */
    public function claimMail(ConversationId $id, string $accountId, int $number): bool;

    /**
     * The conversations the person is in.
     *
     * @return list<ConversationId>
     */
    public function conversationsOf(string $accountId): array;
}
