<?php

declare(strict_types=1);

namespace Modules\Forum\Application\Contracts;

/**
 * The plain text kept with each post (and each earlier version of it) spells out the name a
 * mentioned account had when it was written. When the name stops being true, that copy is
 * written again with the name the account has now.
 */
interface MentionTextsInterface
{
    /**
     * Writes again the plain text of every post and version that mentions the account. A post
     * whose text does not change is left alone. Safe to repeat.
     */
    public function refreshFor(string $accountId): void;
}
