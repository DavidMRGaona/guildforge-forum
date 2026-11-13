<?php

declare(strict_types=1);

namespace Modules\Forum\Domain\Repositories;

use Modules\Forum\Domain\Entities\ConversationReport;
use Modules\Forum\Domain\ValueObjects\ConversationMessageId;
use Modules\Forum\Domain\ValueObjects\ConversationReportId;

interface ConversationReportRepositoryInterface
{
    public function save(ConversationReport $report): void;

    public function find(ConversationReportId $id): ?ConversationReport;

    /**
     * The open report this person made about the message, if any.
     */
    public function openBy(ConversationMessageId $messageId, string $reporterId): ?ConversationReport;

    /**
     * Every open report about the message.
     *
     * @return list<ConversationReport>
     */
    public function openOf(ConversationMessageId $messageId): array;

    /**
     * Open reports, oldest first.
     *
     * @return list<ConversationReport>
     */
    public function open(int $limit): array;

    public function openCount(): int;
}
