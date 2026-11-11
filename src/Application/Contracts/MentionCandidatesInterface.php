<?php

declare(strict_types=1);

namespace Modules\Forum\Application\Contracts;

use Modules\Forum\Application\DTOs\AccountSummaryDTO;
use Modules\Forum\Domain\Entities\Discussion;

/**
 * Whom the editor offers to mention in a discussion: mentionable accounts that may read it.
 * Nobody who cannot read the discussion is ever offered.
 */
interface MentionCandidatesInterface
{
    /**
     * Best matches first: names that start with the text, then names that contain it.
     *
     * @return list<AccountSummaryDTO>
     */
    public function search(Discussion $discussion, string $query, int $limit): array;
}
