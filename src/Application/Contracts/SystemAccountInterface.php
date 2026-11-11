<?php

declare(strict_types=1);

namespace Modules\Forum\Application\Contracts;

/**
 * The local account the forum writes its automatic discussions and notices with. It holds no
 * role and nobody can sign in with it; it carries the site's name.
 */
interface SystemAccountInterface
{
    /**
     * Its account id, creating the account first when it does not exist yet.
     */
    public function id(): string;
}
