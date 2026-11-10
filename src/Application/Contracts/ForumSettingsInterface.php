<?php

declare(strict_types=1);

namespace Modules\Forum\Application\Contracts;

interface ForumSettingsInterface
{
    /**
     * Discussions per page in a category list.
     */
    public function discussionsPerPage(): int;

    /**
     * Posts per page in a discussion.
     */
    public function postsPerPage(): int;
}
