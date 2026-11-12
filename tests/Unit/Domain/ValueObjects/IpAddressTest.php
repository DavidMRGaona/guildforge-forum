<?php

declare(strict_types=1);

namespace Modules\Forum\Tests\Unit\Domain\ValueObjects;

use Modules\Forum\Domain\ValueObjects\IpAddress;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Only addresses on the public internet may be fetched from: anything else could reach the
 * server's own network (SSRF).
 */
final class IpAddressTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function publicAddresses(): iterable
    {
        yield 'IPv4' => ['151.101.1.140'];
        yield 'IPv4 near a private range' => ['172.32.0.1'];
        yield 'IPv4 next to shared space' => ['100.128.0.1'];
        yield 'IPv6 global' => ['2a04:4e42::396'];
        yield 'IPv6 in capitals' => ['2A04:4E42::396'];
    }

    #[DataProvider('publicAddresses')]
    public function test_public_addresses_are_public(string $address): void
    {
        $this->assertTrue(IpAddress::fromString($address)?->isPublic());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function nonPublicAddresses(): iterable
    {
        yield 'this network' => ['0.0.0.0'];
        yield 'private 10/8' => ['10.1.2.3'];
        yield 'shared address space (CGNAT)' => ['100.64.0.1'];
        yield 'loopback' => ['127.0.0.1'];
        yield 'loopback elsewhere in 127/8' => ['127.12.34.56'];
        yield 'link-local' => ['169.254.10.20'];
        yield 'cloud metadata' => ['169.254.169.254'];
        yield 'private 172.16/12' => ['172.16.0.1'];
        yield 'private 172.31' => ['172.31.255.255'];
        yield 'IETF protocol assignments' => ['192.0.0.170'];
        yield 'documentation 192.0.2' => ['192.0.2.1'];
        yield 'private 192.168/16' => ['192.168.1.1'];
        yield 'benchmarking' => ['198.18.0.1'];
        yield 'documentation 198.51.100' => ['198.51.100.7'];
        yield 'documentation 203.0.113' => ['203.0.113.9'];
        yield 'multicast' => ['224.0.0.251'];
        yield 'reserved' => ['240.0.0.1'];
        yield 'broadcast' => ['255.255.255.255'];
        yield 'IPv6 unspecified' => ['::'];
        yield 'IPv6 loopback' => ['::1'];
        yield 'IPv4-mapped loopback' => ['::ffff:127.0.0.1'];
        yield 'IPv4-mapped private, hexadecimal' => ['::ffff:a00:1'];
        yield 'IPv4-mapped public is still not taken' => ['::ffff:151.101.1.140'];
        yield 'IPv4-compatible (deprecated)' => ['::127.0.0.1'];
        yield 'NAT64 to a private address' => ['64:ff9b::10.0.0.1'];
        yield 'NAT64 local-use prefix' => ['64:ff9b:1::1'];
        yield 'discard-only' => ['100::1'];
        yield 'Teredo' => ['2001::1'];
        yield 'IETF assignments' => ['2001:2::1'];
        yield 'documentation' => ['2001:db8::1'];
        yield '6to4' => ['2002:7f00:1::1'];
        yield 'unique local (ULA)' => ['fd00::1'];
        yield 'unique local fc00' => ['fc00::1'];
        yield 'cloud metadata over IPv6' => ['fd00:ec2::254'];
        yield 'link-local IPv6' => ['fe80::1'];
        yield 'site-local (deprecated)' => ['fec0::1'];
        yield 'IPv6 multicast' => ['ff02::1'];
        yield 'outside global unicast' => ['4000::1'];
    }

    #[DataProvider('nonPublicAddresses')]
    public function test_other_addresses_are_not_public(string $address): void
    {
        $ip = IpAddress::fromString($address);

        $this->assertInstanceOf(IpAddress::class, $ip);
        $this->assertFalse($ip->isPublic());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function notAddresses(): iterable
    {
        yield 'empty' => [''];
        yield 'name' => ['localhost'];
        yield 'octal-looking' => ['0177.0.0.1'];
        yield 'short form' => ['127.1'];
        yield 'decimal' => ['2130706433'];
        yield 'zone index' => ['fe80::1%eth0'];
        yield 'brackets' => ['[::1]'];
        yield 'with port' => ['1.2.3.4:80'];
    }

    #[DataProvider('notAddresses')]
    public function test_what_is_not_an_address_gives_nothing(string $value): void
    {
        $this->assertNull(IpAddress::fromString($value));
    }

    public function test_it_knows_its_family_and_writes_itself_for_curl(): void
    {
        $v4 = IpAddress::fromString('151.101.1.140');
        $v6 = IpAddress::fromString('2A04:4E42::396');
        $this->assertInstanceOf(IpAddress::class, $v4);
        $this->assertInstanceOf(IpAddress::class, $v6);

        $this->assertFalse($v4->isV6());
        $this->assertSame('151.101.1.140', $v4->value);
        $this->assertTrue($v6->isV6());
        $this->assertSame('2a04:4e42::396', $v6->value);
        $this->assertSame('[2a04:4e42::396]', $v6->forCurlResolve());
        $this->assertSame('151.101.1.140', $v4->forCurlResolve());
    }

    public function test_two_spellings_of_one_address_are_equal(): void
    {
        $this->assertTrue(IpAddress::fromString('2a04:4e42:0::396')?->equals(IpAddress::fromString('2a04:4e42::0396')));
        $this->assertFalse(IpAddress::fromString('1.1.1.1')?->equals(IpAddress::fromString('1.0.0.1')));
    }
}
