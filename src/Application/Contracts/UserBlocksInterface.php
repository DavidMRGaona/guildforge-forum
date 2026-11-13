<?php

declare(strict_types=1);

namespace Modules\Forum\Application\Contracts;

use DateTimeImmutable;

/**
 * Who does not want private messages from whom. A block is one way: the blocked person may not
 * write to the one who blocked them, nor add them to a conversation. Nobody is told.
 */
interface UserBlocksInterface
{
    /**
     * Blocking again changes nothing.
     */
    public function block(string $blockerId, string $blockedId, DateTimeImmutable $at): void;

    public function unblock(string $blockerId, string $blockedId): void;

    /**
     * The people the person blocked, the latest first.
     *
     * @return list<array{id: string, at: DateTimeImmutable}>
     */
    public function blockedBy(string $blockerId): array;

    /**
     * Those among the given accounts who blocked the person.
     *
     * @param  list<string>  $among
     * @return list<string>
     */
    public function blockersOf(string $blockedId, array $among): array;

    /**
     * Every account that blocked the person.
     *
     * @return list<string>
     */
    public function allBlockersOf(string $blockedId): array;

    /**
     * Every block the person made or received goes.
     */
    public function forget(string $accountId): void;
}
