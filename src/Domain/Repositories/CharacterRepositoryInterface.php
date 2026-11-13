<?php

declare(strict_types=1);

namespace Modules\Forum\Domain\Repositories;

use Modules\Forum\Domain\Entities\Character;
use Modules\Forum\Domain\Exceptions\CharacterNotFoundException;
use Modules\Forum\Domain\ValueObjects\CategoryId;
use Modules\Forum\Domain\ValueObjects\CharacterId;
use Modules\Forum\Domain\ValueObjects\DiscussionId;

interface CharacterRepositoryInterface
{
    public function save(Character $character): void;

    public function find(CharacterId $id): ?Character;

    /**
     * @throws CharacterNotFoundException
     */
    public function findOrFail(CharacterId $id): Character;

    /**
     * @param  list<CharacterId>  $ids
     * @return array<string, Character> by id; missing ids are left out
     */
    public function findMany(array $ids): array;

    /**
     * Every character of the account, retired ones included, by name.
     *
     * @return list<Character>
     */
    public function ownedBy(string $accountId): array;

    public function countOwnedBy(string $accountId): int;

    /**
     * The shared characters of a campaign space, retired ones included, by name.
     *
     * @return list<Character>
     */
    public function sharedIn(CategoryId $space): array;

    public function countSharedIn(CategoryId $space): int;

    /** Whether any post (whatever its status) or earlier version of a post speaks as it */
    public function hasPosts(CharacterId $id): bool;

    /**
     * Which of the characters any post or earlier version speaks as.
     *
     * @param  list<CharacterId>  $ids
     * @return list<string> their ids
     */
    public function idsWithPosts(array $ids): array;

    /**
     * The character of the account's latest post in the discussion that spoke as one, whatever
     * that post's status; null when none did.
     */
    public function lastUsedIn(DiscussionId $discussionId, string $accountId): ?CharacterId;

    /**
     * Deletes it; posts that spoke as it show their account instead.
     */
    public function delete(CharacterId $id): void;
}
