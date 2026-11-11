<?php

declare(strict_types=1);

namespace Modules\Forum\Application\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;
use Modules\Forum\Domain\Entities\Discussion;

/**
 * Who may read, write in and moderate a discussion, decided by its category's access rules.
 * Every answer fails closed: when a rule cannot be read, the answer is no.
 */
interface ForumAccessInterface
{
    /** Whether the person (or a guest, with null) may read the discussion; a hidden one only its moderators */
    public function canRead(?Authenticatable $user, Discussion $discussion): bool;

    /**
     * Whether the person may write in the discussion's category. Whether the discussion takes
     * replies (open or closed) is checked apart.
     */
    public function canWrite(?Authenticatable $user, Discussion $discussion): bool;

    public function canModerate(?Authenticatable $user, Discussion $discussion): bool;
}
