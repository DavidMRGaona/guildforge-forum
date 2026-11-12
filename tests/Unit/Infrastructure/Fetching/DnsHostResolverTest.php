<?php

declare(strict_types=1);

namespace Modules\Forum\Tests\Unit\Infrastructure\Fetching;

use Modules\Forum\Domain\ValueObjects\IpAddress;
use Modules\Forum\Infrastructure\Fetching\DnsHostResolver;
use PHPUnit\Framework\TestCase;

final class DnsHostResolverTest extends TestCase
{
    public function test_names_in_the_hosts_file_are_found_and_are_not_public(): void
    {
        $addresses = (new DnsHostResolver)->addresses('localhost');

        $this->assertNotSame([], $addresses);
        $this->assertContainsOnlyInstancesOf(IpAddress::class, $addresses);
        foreach ($addresses as $address) {
            $this->assertFalse($address->isPublic());
        }
    }

    public function test_a_name_that_does_not_exist_gives_nothing(): void
    {
        $this->assertSame([], (new DnsHostResolver)->addresses('nothing-here.invalid'));
    }
}
