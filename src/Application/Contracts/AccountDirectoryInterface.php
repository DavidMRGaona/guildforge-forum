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
     * The public names (`display_name`) of those accounts that have one, never the account name
     * `namesOf()` falls back to; an anonymized account keeps its generic name, which tells
     * nothing. What names the writer of a post that speaks, or spoke, as a character
     * (forum#139). Deactivated accounts included, like `namesOf()`.
     *
     * @param  list<string>  $accountIds
     * @return array<string, string> by account id
     */
    public function publicNamesOf(array $accountIds): array;

    /**
     * Those of the ids that may be mentioned: accounts that exist and are neither deactivated
     * nor anonymized.
     *
     * @param  list<string>  $accountIds
     * @return list<string>
     */
    public function mentionable(array $accountIds): array;

    /**
     * Those of the ids whose account exists, is not anonymized and has a public name
     * (`display_name`): the only people the forum puts in front of others by name when someone
     * writes, mentions or chooses them. Lower-case ids, in no particular order.
     *
     * @param  list<string>  $accountIds
     * @return list<string>
     */
    public function withPublicName(array $accountIds): array;

    /**
     * The profile avatars (the stored public id) of those accounts that are active (neither
     * deactivated nor anonymized) and have one. Read when painting, never copied, so a changed
     * or removed avatar shows at once and erasing an account leaves nothing behind.
     *
     * @param  list<string>  $accountIds
     * @return array<string, string> by account id
     */
    public function avatarsOf(array $accountIds): array;

    /**
     * The account with that id, while it is active (neither deactivated nor anonymized).
     */
    public function active(string $accountId): ?Authenticatable;
}
