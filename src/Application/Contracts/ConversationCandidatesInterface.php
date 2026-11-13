<?php

declare(strict_types=1);

namespace Modules\Forum\Application\Contracts;

/**
 * The people a private conversation may be started with or extended to, found by name for the
 * selector: active accounts that are not the forum's system account. Never their email.
 */
interface ConversationCandidatesInterface
{
    /**
     * @param  list<string>  $excluded  ids left out (the person, those in the conversation already, those who blocked them)
     * @return list<array{id: string, name: string}>
     */
    public function search(string $query, array $excluded, int $limit): array;
}
