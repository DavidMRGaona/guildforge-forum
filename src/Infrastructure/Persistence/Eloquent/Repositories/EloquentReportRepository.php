<?php

declare(strict_types=1);

namespace Modules\Forum\Infrastructure\Persistence\Eloquent\Repositories;

use Modules\Forum\Domain\Entities\Report;
use Modules\Forum\Domain\Enums\ReportReason;
use Modules\Forum\Domain\Enums\ReportResolution;
use Modules\Forum\Domain\Repositories\ReportRepositoryInterface;
use Modules\Forum\Domain\ValueObjects\PostId;
use Modules\Forum\Domain\ValueObjects\ReportId;
use Modules\Forum\Infrastructure\Persistence\Eloquent\Models\ReportModel;

final class EloquentReportRepository implements ReportRepositoryInterface
{
    public function save(Report $report): void
    {
        ReportModel::query()->updateOrCreate(['id' => $report->id()->value], [
            'post_id' => $report->postId()->value,
            'reporter_id' => $report->reporterId(),
            'reason' => $report->reason()->value,
            'note' => $report->note(),
            'resolution' => $report->resolution()?->value,
            'resolved_by' => $report->resolvedBy(),
            'resolved_at' => $report->resolvedAt(),
            'created_at' => $report->createdAt(),
        ]);
    }

    public function find(ReportId $id): ?Report
    {
        $model = ReportModel::query()->find($id->value);

        return $model instanceof ReportModel ? $this->toEntity($model) : null;
    }

    public function openOf(PostId $postId): array
    {
        return array_values(ReportModel::query()
            ->where('post_id', $postId->value)
            ->whereNull('resolution')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->map(fn (ReportModel $model): Report => $this->toEntity($model))
            ->all());
    }

    public function openBy(PostId $postId, string $reporterId): ?Report
    {
        $model = ReportModel::query()
            ->where('post_id', $postId->value)
            ->where('reporter_id', strtolower($reporterId))
            ->whereNull('resolution')
            ->first();

        return $model instanceof ReportModel ? $this->toEntity($model) : null;
    }

    public function countOpen(): int
    {
        return ReportModel::query()->whereNull('resolution')->count();
    }

    private function toEntity(ReportModel $model): Report
    {
        return new Report(
            id: ReportId::fromString($model->id),
            postId: PostId::fromString($model->post_id),
            reporterId: $model->reporter_id,
            // A reason it cannot read is still a report: it stays open for moderation
            reason: ReportReason::tryFrom($model->reason) ?? ReportReason::Other,
            note: $model->note,
            createdAt: $model->created_at->toDateTimeImmutable(),
            resolution: $model->resolution === null ? null : (ReportResolution::tryFrom($model->resolution) ?? ReportResolution::NoAction),
            resolvedBy: $model->resolved_by,
            resolvedAt: $model->resolved_at?->toDateTimeImmutable(),
        );
    }
}
