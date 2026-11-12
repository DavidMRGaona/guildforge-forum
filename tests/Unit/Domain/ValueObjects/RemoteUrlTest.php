<?php

declare(strict_types=1);

namespace Modules\Forum\Tests\Unit\Domain\ValueObjects;

use Modules\Forum\Domain\ValueObjects\RemoteUrl;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Addresses the forum fetches from: https on its own port, a host name (never an address), no
 * credentials and nothing a browser and the server could read differently.
 */
final class RemoteUrlTest extends TestCase
{
    public function test_an_https_address_keeps_its_path_and_query_with_the_host_in_lowercase(): void
    {
        $url = RemoteUrl::tryFrom('https://I.Imgur.com/AbC123.png?x=1');

        $this->assertInstanceOf(RemoteUrl::class, $url);
        $this->assertSame('i.imgur.com', $url->host);
        $this->assertSame('https://i.imgur.com/AbC123.png?x=1', $url->value);
        $this->assertSame('/AbC123.png?x=1', $url->pathAndQuery());
    }

    public function test_the_default_port_written_out_is_dropped(): void
    {
        $this->assertSame('https://i.imgur.com/a.png', RemoteUrl::tryFrom('https://i.imgur.com:443/a.png')?->value);
    }

    public function test_an_address_without_path_gets_the_root(): void
    {
        $this->assertSame('https://imgur.com/', RemoteUrl::tryFrom('https://imgur.com')?->value);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function refused(): iterable
    {
        yield 'http' => ['http://i.imgur.com/a.png'];
        yield 'no scheme' => ['i.imgur.com/a.png'];
        yield 'protocol-relative' => ['//i.imgur.com/a.png'];
        yield 'ftp' => ['ftp://i.imgur.com/a.png'];
        yield 'data' => ['data:image/png;base64,AAAA'];
        yield 'javascript' => ['javascript:alert(1)'];
        yield 'credentials' => ['https://user:pass@i.imgur.com/a.png'];
        yield 'user only' => ['https://user@i.imgur.com/a.png'];
        yield 'other port' => ['https://i.imgur.com:8443/a.png'];
        yield 'IPv4 literal' => ['https://151.101.1.140/a.png'];
        yield 'IPv6 literal' => ['https://[2a04:4e42::396]/a.png'];
        yield 'decimal address' => ['https://2130706433/a.png'];
        yield 'single label' => ['https://localhost/a.png'];
        yield 'trailing dot' => ['https://i.imgur.com./a.png'];
        yield 'non-ASCII host' => ['https://imgür.com/a.png'];
        yield 'underscore' => ['https://i_mgur.com/a.png'];
        yield 'backslash' => ['https://i.imgur.com\\@evil.example/a.png'];
        yield 'space' => ['https://i.imgur.com/a b.png'];
        yield 'control character' => ["https://i.imgur.com/a\n.png"];
        yield 'fragment' => ['https://i.imgur.com/a.png#x'];
        yield 'empty host' => ['https:///a.png'];
        yield 'too long' => ['https://i.imgur.com/'.str_repeat('a', RemoteUrl::MAX_LENGTH)];
        yield 'empty' => [''];
    }

    #[DataProvider('refused')]
    public function test_anything_else_is_refused(string $value): void
    {
        $this->assertNull(RemoteUrl::tryFrom($value));
    }

    public function test_a_relative_location_resolves_on_the_same_host(): void
    {
        $base = RemoteUrl::tryFrom('https://i.imgur.com/a/b.png');
        $this->assertInstanceOf(RemoteUrl::class, $base);

        $this->assertSame('https://i.imgur.com/c.png', $base->resolve('/c.png')?->value);
        $this->assertSame('https://i.imgur.com/a/c.png', $base->resolve('c.png')?->value);
        $this->assertSame('https://i.imgur.com/c.png?d=1', $base->resolve('../c.png?d=1')?->value);
        $this->assertSame('https://other.example/c.png', $base->resolve('https://other.example/c.png')?->value);
        $this->assertSame('https://other.example/c.png', $base->resolve('//other.example/c.png')?->value);
        $this->assertNull($base->resolve('http://i.imgur.com/c.png'));
        $this->assertNull($base->resolve(''));
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function hostLists(): iterable
    {
        yield 'the host itself' => ['imgur.com', true];
        yield 'a subdomain' => ['i.imgur.com', true];
        yield 'a deeper subdomain' => ['a.b.imgur.com', true];
        yield 'another domain ending alike' => ['notimgur.com', false];
        yield 'the listed name inside another' => ['imgur.com.evil.example', false];
        yield 'an unlisted host' => ['evil.example', false];
    }

    #[DataProvider('hostLists')]
    public function test_a_listed_domain_covers_its_subdomains_only(string $host, bool $allowed): void
    {
        $url = RemoteUrl::tryFrom("https://{$host}/a.png");

        $this->assertSame($allowed, $url?->hostIsIn(['imgur.com', 'postimg.cc']));
    }

    public function test_host_names_are_checked_for_the_list(): void
    {
        $this->assertTrue(RemoteUrl::isHostName('imgur.com'));
        $this->assertTrue(RemoteUrl::isHostName('i.ibb.co'));
        $this->assertFalse(RemoteUrl::isHostName('Imgur.com'));
        $this->assertFalse(RemoteUrl::isHostName('imgur'));
        $this->assertFalse(RemoteUrl::isHostName('*.imgur.com'));
        $this->assertFalse(RemoteUrl::isHostName('https://imgur.com'));
        $this->assertFalse(RemoteUrl::isHostName('1.2.3.4'));
        $this->assertFalse(RemoteUrl::isHostName('-imgur.com'));
    }
}
