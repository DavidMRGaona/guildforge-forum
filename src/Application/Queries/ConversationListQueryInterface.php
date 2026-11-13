<?php

declare(strict_types=1);

namespace Modules\Forum\Application\Queries;

/**
 * The conversations a person is in, the latest message first. Only theirs: there is no way to
 * list anyone else's.
 */
interface ConversationListQueryInterface
{
    /**
     * @return array{rows: list<ConversationRowDTO>, total: int}
     */
    public function page(string $accountId, int $page, int $perPage): array;

    /**
     * How many of the person's conversations have messages by others they have not read.
     */
    public function unreadCount(string $accountId): int;
}
