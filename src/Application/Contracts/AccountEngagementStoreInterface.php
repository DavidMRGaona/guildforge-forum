<?php

declare(strict_types=1);

namespace Modules\Forum\Application\Contracts;

/**
 * What the forum stores only because of one person's choices and habits (not what they said,
 * and not what moderation recorded about them).
 */
interface AccountEngagementStoreInterface
{
    /**
     * Deletes the account's likes, follows of discussions and categories, reading marks,
     * forum preferences and the reports it filed. Safe to repeat.
     */
    public function forget(string $accountId): void;
}
