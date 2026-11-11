<?php

declare(strict_types=1);

namespace Modules\Forum\Application\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * The accounts the forum shows by name: mentions, quote authors, editors of a version.
 */
interface AccountDirectoryInterface
{
    /**
     * The names accounts show now, for those that still exist (deactivated and anonymized ones
     * included, with the name they show).
     *
     * @param  list<string>  $accountIds
     * @return array<string, string> by account id
     */
    public function namesOf(array $accountIds): array;

    /**
     * Those of the ids that may be mentioned: accounts that exist and are neither deactivated
     * nor anonymized.
     *
     * @param  list<string>  $accountIds
     * @return list<string>
     */
    public function mentionable(array $accountIds): array;

    /**
     * The account with that id, while it is active (neither deactivated nor anonymized).
     */
    public function active(string $accountId): ?Authenticatable;
}
