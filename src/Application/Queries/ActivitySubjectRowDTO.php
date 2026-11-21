<?php

declare(strict_types=1);

namespace Modules\Forum\Application\Queries;

use DateTimeImmutable;

/**
 * A post or a discussion that counts as activity (forum#151): its id, the account that wrote or
 * opened it and when it was published or opened.
 */
final readonly class ActivitySubjectRowDTO
{
    public function __construct(
        public string $id,
        public string $authorId,
        public DateTimeImmutable $occurredAt,
    ) {}
}
