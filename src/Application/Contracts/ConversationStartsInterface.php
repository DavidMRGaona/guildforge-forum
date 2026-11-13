<?php

declare(strict_types=1);

namespace Modules\Forum\Application\Contracts;

use DateTimeImmutable;

/**
 * When each person started a conversation, for the limit of new conversations per day. A
 * conversation that goes later still counts.
 */
interface ConversationStartsInterface
{
    /**
     * Holds the person's starts until the transaction ends, so two starts at once count each other.
     */
    public function lockFor(string $accountId): void;

    public function record(string $accountId, DateTimeImmutable $at): void;

    public function countSince(string $accountId, DateTimeImmutable $since): int;

    /**
     * The oldest start counted since then, to say when another may be started.
     */
    public function oldestSince(string $accountId, DateTimeImmutable $since): ?DateTimeImmutable;

    public function forget(string $accountId): void;
}
