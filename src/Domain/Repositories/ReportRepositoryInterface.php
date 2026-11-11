<?php

declare(strict_types=1);

namespace Modules\Forum\Domain\Repositories;

use Modules\Forum\Domain\Entities\Report;
use Modules\Forum\Domain\ValueObjects\PostId;
use Modules\Forum\Domain\ValueObjects\ReportId;

interface ReportRepositoryInterface
{
    public function save(Report $report): void;

    public function find(ReportId $id): ?Report;

    /**
     * The post's open reports, oldest first.
     *
     * @return list<Report>
     */
    public function openOf(PostId $postId): array;

    /**
     * The open report this person made about the post, if any.
     */
    public function openBy(PostId $postId, string $reporterId): ?Report;

    public function countOpen(): int;
}
