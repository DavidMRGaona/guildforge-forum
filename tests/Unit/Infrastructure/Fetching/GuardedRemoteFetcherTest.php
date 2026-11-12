<?php

declare(strict_types=1);

namespace Modules\Forum\Tests\Unit\Infrastructure\Fetching;

use Closure;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\TransferStats;
use Modules\Forum\Application\Contracts\HostResolverInterface;
use Modules\Forum\Domain\Exceptions\RemoteFetchException;
use Modules\Forum\Domain\ValueObjects\IpAddress;
use Modules\Forum\Domain\ValueObjects\RemoteUrl;
use Modules\Forum\Infrastructure\Fetching\CappedStream;
use Modules\Forum\Infrastructure\Fetching\GuardedRemoteFetcher;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

/**
 * Every way a fetch could reach the server's own network is refused, and anything that cannot
 * be checked counts as refused.
 */
final class GuardedRemoteFetcherTest extends TestCase
{
    private const string PUBLIC_IP = '151.101.1.140';

    /** @var array<string, list<string>> */
    private array $dns = [];

    /** @var list<string> */
    private array $lookups = [];

    /** @var list<array{request: RequestInterface, options: array<string, mixed>}> */
    private array $sent = [];

    /** @var list<Closure(RequestInterface, array<string, mixed>): PromiseInterface> */
    private array $answers = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->dns = ['i.imgur.com' => [self::PUBLIC_IP]];
    }

    public function test_a_public_host_is_fetched_with_its_checked_address_pinned(): void
    {
        $this->answers[] = $this->answer(new Response(200, [], 'png-bytes'));

        $resource = $this->fetcher()->fetch($this->url('https://i.imgur.com/a.png'), 1000, 'image/*');

        $this->assertSame('png-bytes', $resource->bytes);
        $this->assertSame('https://i.imgur.com/a.png', $resource->url->value);
        $this->assertCount(1, $this->sent);

        $options = $this->sent[0]['options'];
        $this->assertFalse($options['allow_redirects']);
        $this->assertFalse($options['decode_content']);
        $this->assertTrue($options['verify']);
        $curl = $options['curl'];
        $this->assertIsArray($curl);
        // The connection goes to the address that was checked, not to a second lookup
        $this->assertSame(['i.imgur.com:443:'.self::PUBLIC_IP], $curl[CURLOPT_RESOLVE]);
        // Only the options Guzzle still takes raw: the size is capped by on_headers and the sink
        $this->assertSame([CURLOPT_RESOLVE], array_keys($curl));
        $this->assertSame(['https'], $options['protocols']);
        // No proxy from the environment
        $this->assertSame('', $options['proxy']);
        $this->assertInstanceOf(CappedStream::class, $options['sink']);
        $this->assertGreaterThan(0, $options['timeout']);
        $this->assertGreaterThan(0, $options['connect_timeout']);
        $this->assertSame('identity', $this->sent[0]['request']->getHeaderLine('Accept-Encoding'));
        $this->assertSame('', $this->sent[0]['request']->getHeaderLine('Cookie'));
    }

    public function test_an_ipv6_address_is_pinned_between_brackets(): void
    {
        $this->dns['i.imgur.com'] = ['2a04:4e42::396'];
        $this->answers[] = $this->answer(new Response(200, [], 'x'), '2a04:4e42::396');

        $this->fetcher()->fetch($this->url('https://i.imgur.com/a.png'), 1000, 'image/*');

        $this->assertSame(['i.imgur.com:443:[2a04:4e42::396]'], $this->sent[0]['options']['curl'][CURLOPT_RESOLVE] ?? null);
    }

    /**
     * @return iterable<string, array{list<string>}>
     */
    public static function nonPublicLookups(): iterable
    {
        yield 'loopback' => [['127.0.0.1']];
        yield 'private' => [['10.0.0.8']];
        yield 'link-local metadata' => [['169.254.169.254']];
        yield 'IPv6 loopback' => [['::1']];
        yield 'IPv6 unique local' => [['fd00::5']];
        yield 'IPv4-mapped loopback' => [['::ffff:127.0.0.1']];
        yield 'one bad address among good ones' => [[self::PUBLIC_IP, '192.168.1.10']];
    }

    /**
     * @param  list<string>  $addresses
     */
    #[DataProvider('nonPublicLookups')]
    public function test_a_host_resolving_to_any_non_public_address_is_never_contacted(array $addresses): void
    {
        $this->dns['i.imgur.com'] = $addresses;

        $this->assertRefused(RemoteFetchException::NOT_PUBLIC, 'https://i.imgur.com/a.png');
        $this->assertSame([], $this->sent);
    }

    public function test_a_host_without_addresses_is_refused(): void
    {
        $this->dns['i.imgur.com'] = [];

        $this->assertRefused(RemoteFetchException::UNRESOLVABLE, 'https://i.imgur.com/a.png');
        $this->assertSame([], $this->sent);
    }

    public function test_a_connection_made_to_another_address_is_refused(): void
    {
        // DNS rebinding or a resolver bypass: the transfer reports a private peer
        $this->answers[] = $this->answer(new Response(200, [], 'secret'), '10.0.0.5');

        $this->assertRefused(RemoteFetchException::CONNECTION_MISMATCH, 'https://i.imgur.com/a.png');
    }

    public function test_a_connection_whose_peer_cannot_be_read_is_refused(): void
    {
        $this->answers[] = $this->answer(new Response(200, [], 'secret'), null);

        $this->assertRefused(RemoteFetchException::CONNECTION_MISMATCH, 'https://i.imgur.com/a.png');
    }

    public function test_a_redirect_within_the_host_is_followed_and_checked_again(): void
    {
        $this->answers[] = $this->answer(new Response(302, ['Location' => '/b.png']));
        $this->answers[] = $this->answer(new Response(200, [], 'b-bytes'));

        $resource = $this->fetcher()->fetch($this->url('https://i.imgur.com/a.png'), 1000, 'image/*');

        $this->assertSame('b-bytes', $resource->bytes);
        $this->assertSame('https://i.imgur.com/b.png', $resource->url->value);
        $this->assertSame('https://i.imgur.com/b.png', (string) $this->sent[1]['request']->getUri());
        // Each hop looks the host up and checks it again
        $this->assertSame(['i.imgur.com', 'i.imgur.com'], $this->lookups);
    }

    public function test_a_redirect_within_the_host_is_refused_when_the_host_now_resolves_privately(): void
    {
        $this->answers[] = function (RequestInterface $request, array $options): PromiseInterface {
            // The next lookup answers with a private address (rebinding between hops)
            $this->dns['i.imgur.com'] = ['127.0.0.1'];

            return $this->answer(new Response(301, ['Location' => 'https://i.imgur.com/b.png']))($request, $options);
        };

        $this->assertRefused(RemoteFetchException::NOT_PUBLIC, 'https://i.imgur.com/a.png');
        $this->assertCount(1, $this->sent);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function foreignLocations(): iterable
    {
        yield 'another host' => ['https://evil.example/a.png'];
        yield 'a sibling subdomain' => ['https://imgur.com/a.png'];
        yield 'protocol-relative to another host' => ['//evil.example/a.png'];
        yield 'plain http' => ['http://i.imgur.com/a.png'];
        yield 'an address' => ['https://127.0.0.1/a.png'];
        yield 'another scheme' => ['file:///etc/passwd'];
        yield 'nothing' => [''];
    }

    #[DataProvider('foreignLocations')]
    public function test_a_redirect_elsewhere_is_refused(string $location): void
    {
        $this->answers[] = $this->answer(new Response(302, ['Location' => $location]));

        $this->assertRefused(RemoteFetchException::REDIRECT_ELSEWHERE, 'https://i.imgur.com/a.png');
        $this->assertCount(1, $this->sent);
    }

    public function test_redirects_stop_after_a_few(): void
    {
        for ($hop = 0; $hop <= GuardedRemoteFetcher::MAX_REDIRECTS; $hop++) {
            $this->answers[] = $this->answer(new Response(307, ['Location' => "/hop-{$hop}.png"]));
        }

        $this->assertRefused(RemoteFetchException::TOO_MANY_REDIRECTS, 'https://i.imgur.com/a.png');
        $this->assertCount(GuardedRemoteFetcher::MAX_REDIRECTS + 1, $this->sent);
    }

    public function test_an_answer_larger_than_allowed_is_refused(): void
    {
        $this->answers[] = $this->answer(new Response(200, [], str_repeat('x', 1001)));

        $this->assertRefused(RemoteFetchException::TOO_LARGE, 'https://i.imgur.com/a.png', 1000);
    }

    public function test_a_body_growing_past_the_limit_stops_the_transfer_as_it_arrives(): void
    {
        $this->answers[] = static function (RequestInterface $request, array $options): PromiseInterface {
            // As curl does: it writes the body to the sink, and a short write aborts (error 23)
            $sink = $options['sink'];
            assert($sink instanceof CappedStream);
            $sink->write(str_repeat('x', 600));
            $written = $sink->write(str_repeat('x', 600));

            return Create::rejectionFor(new RequestException(
                $written === 0 ? 'cURL error 23: Failure writing output' : 'unexpected',
                $request,
                null,
                null,
                ['errno' => 23],
            ));
        };

        $this->assertRefused(RemoteFetchException::TOO_LARGE, 'https://i.imgur.com/a.png');
    }

    public function test_an_announced_size_past_the_limit_is_refused_before_the_body(): void
    {
        $this->answers[] = static function (RequestInterface $request, array $options): PromiseInterface {
            $onHeaders = $options['on_headers'] ?? null;
            assert(is_callable($onHeaders));

            try {
                $onHeaders(new Response(200, ['Content-Length' => '5000']));
            } catch (\Throwable $exception) {
                return Create::rejectionFor(new RequestException('An error was encountered during the on_headers event', $request, null, $exception));
            }

            return Create::promiseFor(new Response(200));
        };

        $this->assertRefused(RemoteFetchException::TOO_LARGE, 'https://i.imgur.com/a.png');
    }

    public function test_the_capped_sink_takes_up_to_its_limit_only(): void
    {
        $sink = new CappedStream(10);

        $this->assertSame(6, $sink->write('abcdef'));
        $this->assertSame(4, $sink->write('ghij'));
        $this->assertFalse($sink->exceeded());
        $this->assertSame(0, $sink->write('k'));
        $this->assertTrue($sink->exceeded());
        $this->assertSame('abcdefghij', (string) $sink);
    }

    public function test_timeouts_and_network_errors_are_refused(): void
    {
        $this->answers[] = static fn (RequestInterface $request): PromiseInterface => Create::rejectionFor(
            new ConnectException('cURL error 28: Operation timed out', $request, null, ['errno' => 28]),
        );

        $this->assertRefused(RemoteFetchException::UNAVAILABLE, 'https://i.imgur.com/a.png');
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function statuses(): iterable
    {
        yield 'not found' => [404];
        yield 'gone' => [410];
        yield 'server error' => [500];
        yield 'no content' => [204];
        yield 'not modified' => [304];
    }

    #[DataProvider('statuses')]
    public function test_any_answer_but_200_is_refused(int $status): void
    {
        $this->answers[] = $this->answer(new Response($status));

        $this->assertRefused(RemoteFetchException::HTTP_STATUS, 'https://i.imgur.com/a.png');
    }

    private function fetcher(): GuardedRemoteFetcher
    {
        $lookup = function (string $host): array {
            $this->lookups[] = $host;

            return array_values(array_filter(array_map(IpAddress::fromString(...), $this->dns[$host] ?? [])));
        };
        $resolver = new readonly class($lookup) implements HostResolverInterface
        {
            /**
             * @param  Closure(string): list<IpAddress>  $lookup
             */
            public function __construct(private Closure $lookup) {}

            public function addresses(string $host): array
            {
                return ($this->lookup)($host);
            }
        };

        $handler = function (RequestInterface $request, array $options): PromiseInterface {
            $this->sent[] = ['request' => $request, 'options' => $options];
            $answer = array_shift($this->answers);
            $this->assertNotNull($answer, 'An unexpected request was made');

            return $answer($request, $options);
        };

        return new GuardedRemoteFetcher($resolver, $handler);
    }

    /**
     * An answer reporting the peer it connected to, as curl does.
     *
     * @return Closure(RequestInterface, array<string, mixed>): PromiseInterface
     */
    private function answer(Response $response, ?string $peer = self::PUBLIC_IP): Closure
    {
        return static function (RequestInterface $request, array $options) use ($response, $peer): PromiseInterface {
            $onStats = $options['on_stats'] ?? null;
            if (is_callable($onStats)) {
                $onStats(new TransferStats($request, $response, 0.01, null, $peer === null ? [] : ['primary_ip' => $peer]));
            }

            return Create::promiseFor($response);
        };
    }

    private function url(string $value): RemoteUrl
    {
        $url = RemoteUrl::tryFrom($value);
        $this->assertInstanceOf(RemoteUrl::class, $url);

        return $url;
    }

    private function assertRefused(string $reason, string $url, int $maxBytes = 1000): void
    {
        try {
            $this->fetcher()->fetch($this->url($url), $maxBytes, 'image/*');
            $this->fail('The fetch should have been refused');
        } catch (RemoteFetchException $exception) {
            $this->assertSame($reason, $exception->reason, $exception->getMessage());
        }
    }
}
