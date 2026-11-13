<?php

declare(strict_types=1);

namespace Modules\Forum\Application\Queries;

use DateTimeImmutable;

/**
 * One conversation in a person's list.
 */
final readonly class ConversationRowDTO
{
    /**
     * @param  int  $unread  messages by others after the last entry the person read
     * @param  list<string>  $participantIds  who is in it now, the person included
     */
    public function __construct(
        public string $id,
        public string $title,
        public DateTimeImmutable $lastActivityAt,
        public int $unread,
        public array $participantIds,
    ) {}
}
