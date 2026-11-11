<?php

declare(strict_types=1);

namespace Modules\Forum\Domain\Repositories;

use Modules\Forum\Domain\Entities\ModerationLogEntry;

/**
 * The moderation log. Append only: nothing reads an entry to change or delete it.
 */
interface ModerationLogRepositoryInterface
{
    public function record(ModerationLogEntry $entry): void;
}
