<?php

declare(strict_types=1);

namespace Modules\Forum\Infrastructure\Fetching;

use Modules\Forum\Application\Contracts\HostResolverInterface;
use Modules\Forum\Domain\ValueObjects\IpAddress;

/**
 * Looks a host up in DNS (A and AAAA) and, when that finds nothing, through the system
 * resolver (which also reads the hosts file). Every address found is returned, so a single
 * private one among them makes the fetcher refuse the host.
 */
final readonly class DnsHostResolver implements HostResolverInterface
{
    public function addresses(string $host): array
    {
        $found = [];

        $records = @dns_get_record($host, DNS_A | DNS_AAAA);
        foreach (is_array($records) ? $records : [] as $record) {
            $value = $record['ip'] ?? $record['ipv6'] ?? null;
            $address = is_string($value) ? IpAddress::fromString($value) : null;
            if ($address instanceof IpAddress) {
                $found[$address->value] = $address;
            }
        }

        if ($found === []) {
            $system = @gethostbynamel($host);
            foreach (is_array($system) ? $system : [] as $value) {
                $address = IpAddress::fromString($value);
                if ($address instanceof IpAddress) {
                    $found[$address->value] = $address;
                }
            }
        }

        return array_values($found);
    }
}
