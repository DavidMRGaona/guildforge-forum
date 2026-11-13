<?php

declare(strict_types=1);

namespace Modules\Forum\Infrastructure\Persistence\Eloquent\Repositories;

use Modules\Forum\Domain\Entities\ConversationReport;
use Modules\Forum\Domain\Enums\ReportReason;
use Modules\Forum\Domain\Enums\ReportResolution;
use Modules\Forum\Domain\Repositories\ConversationReportRepositoryInterface;
use Modules\Forum\Domain\ValueObjects\ConversationMessageId;
use Modules\Forum\Domain\ValueObjects\ConversationReportId;
use Modules\Forum\Infrastructure\Persistence\Eloquent\Models\ConversationReportModel;

final readonly class EloquentConversationReportRepository implements ConversationReportRepositoryInterface
{
    public function save(ConversationReport $report): void
    {
        ConversationReportModel::query()->updateOrCreate(['id' => $report->id()->value], [
            'message_id' => $report->messageId()?->value,
            'message_author_id' => $report->messageAuthorId(),
            'reporter_id' => $report->reporterId(),
            'reason' => $report->reason()->value,
            'note' => $report->note(),
            'context' => $report->context(),
            'resolution' => $report->resolution()?->value,
            'resolved_by' => $report->resolvedBy(),
            'resolved_at' => $report->resolvedAt(),
            'created_at' => $report->createdAt(),
        ]);
    }

    public function find(ConversationReportId $id): ?ConversationReport
    {
        $model = ConversationReportModel::query()->find($id->value);

        return $model instanceof ConversationReportModel ? $this->toEntity($model) : null;
    }

    public function openBy(ConversationMessageId $messageId, string $reporterId): ?ConversationReport
    {
        $model = ConversationReportModel::query()
            ->where('message_id', $messageId->value)
            ->where('reporter_id', strtolower($reporterId))
            ->whereNull('resolution')
            ->first();

        return $model instanceof ConversationReportModel ? $this->toEntity($model) : null;
    }

    public function openOf(ConversationMessageId $messageId): array
    {
        return array_values(ConversationReportModel::query()
            ->where('message_id', $messageId->value)
            ->whereNull('resolution')
            ->orderBy('created_at')
            ->get()
            ->map($this->toEntity(...))
            ->all());
    }

    public function open(int $limit): array
    {
        return array_values(ConversationReportModel::query()
            ->whereNull('resolution')
            ->orderBy('created_at')
            ->orderBy('id')
            ->limit($limit)
            ->get()
            ->map($this->toEntity(...))
            ->all());
    }

    public function openCount(): int
    {
        return ConversationReportModel::query()->whereNull('resolution')->count();
    }

    private function toEntity(ConversationReportModel $model): ConversationReport
    {
        return new ConversationReport(
            id: ConversationReportId::fromString($model->id),
            messageId: $model->message_id === null ? null : ConversationMessageId::fromString($model->message_id),
            messageAuthorId: $model->message_author_id,
            reporterId: $model->reporter_id,
            reason: ReportReason::tryFrom($model->reason) ?? ReportReason::Other,
            note: $model->note,
            context: $this->context($model->context),
            createdAt: $model->created_at->toDateTimeImmutable(),
            resolution: $model->resolution === null ? null : (ReportResolution::tryFrom($model->resolution) ?? ReportResolution::NoAction),
            resolvedBy: $model->resolved_by,
            resolvedAt: $model->resolved_at?->toDateTimeImmutable(),
        );
    }

    /**
     * The stored copy, each entry read field by field: anything malformed shows as empty text.
     *
     * @return list<array{number: int, kind: string, authorId: string|null, subjectUserId: string|null, text: string, reported: bool, at: string}>
     */
    private function context(mixed $stored): array
    {
        if (! is_array($stored)) {
            return [];
        }

        $entries = [];
        foreach ($stored as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            $entries[] = [
                'number' => is_int($entry['number'] ?? null) ? $entry['number'] : 0,
                'kind' => is_string($entry['kind'] ?? null) ? $entry['kind'] : 'message',
                'authorId' => is_string($entry['authorId'] ?? null) ? $entry['authorId'] : null,
                'subjectUserId' => is_string($entry['subjectUserId'] ?? null) ? $entry['subjectUserId'] : null,
                'text' => is_string($entry['text'] ?? null) ? $entry['text'] : '',
                'reported' => ($entry['reported'] ?? false) === true,
                'at' => is_string($entry['at'] ?? null) ? $entry['at'] : '',
            ];
        }

        return $entries;
    }
}
