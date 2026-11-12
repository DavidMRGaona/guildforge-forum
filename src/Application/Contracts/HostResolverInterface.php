<?php

declare(strict_types=1);

namespace Modules\Forum\Application\Contracts;

use Modules\Forum\Domain\ValueObjects\IpAddress;

/**
 * Looks up the addresses of a host name. Whoever fetches checks every one before connecting.
 */
interface HostResolverInterface
{
    /**
     * @return list<IpAddress> every address found; empty when there is none or the lookup failed
     */
    public function addresses(string $host): array;
}
