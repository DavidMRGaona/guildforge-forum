<?php

declare(strict_types=1);

namespace Modules\Forum\Domain\Repositories;

use DateTimeImmutable;
use Modules\Forum\Domain\ValueObjects\MemberTitle;

/**
 * The title each person holds in the forum.
 */
interface MemberTitleRepositoryInterface
{
    public function find(string $userId): ?MemberTitle;

    /**
     * The titles of those people whose account is still active (neither deactivated nor
     * anonymized), in one query.
     *
     * @param  list<string>  $userIds
     * @return array<string, string> by account id, in lower case
     */
    public function titlesOf(array $userIds): array;

    public function save(string $userId, MemberTitle $title, string $moderatorId, DateTimeImmutable $at): void;

    public function forget(string $userId): void;
}
