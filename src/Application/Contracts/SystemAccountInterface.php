<?php

declare(strict_types=1);

namespace Modules\Forum\Application\Contracts;

/**
 * The local account the forum writes its automatic discussions and notices with. It holds no
 * role and nobody can sign in with it; it carries the site's name. It never receives anything:
 * notices, mail, digests, mentions, follows, likes or quotes of its posts skip it
 * (isSystemAccount()), and it is never offered as someone to mention.
 */
interface SystemAccountInterface
{
    /**
     * Its account id, creating the account first when it does not exist yet.
     */
    public function id(): string;

    /**
     * Whether the account is the system account (never creates it).
     */
    public function isSystemAccount(?string $accountId): bool;
}
