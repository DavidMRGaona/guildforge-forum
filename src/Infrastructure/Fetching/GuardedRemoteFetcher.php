<?php

declare(strict_types=1);

namespace Modules\Forum\Infrastructure\Fetching;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Handler\CurlHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\TransferStats;
use Modules\Forum\Application\Contracts\HostResolverInterface;
use Modules\Forum\Application\Contracts\RemoteFetcherInterface;
use Modules\Forum\Application\DTOs\RemoteResourceDTO;
use Modules\Forum\Domain\Exceptions\RemoteFetchException;
use Modules\Forum\Domain\ValueObjects\IpAddress;
use Modules\Forum\Domain\ValueObjects\RemoteUrl;
use Psr\Http\Message\ResponseInterface;

/**
 * Fetches over curl with every hop guarded:
 *
 * - the host is looked up here and every address must be public, or nothing is sent;
 * - curl is pinned to the checked address (CURLOPT_RESOLVE), so it never looks the host up
 *   again (DNS rebinding), and after the transfer the peer it reports must be that address;
 * - no proxy from the environment, https only, TLS verified, no cookies, no compression (so
 *   the size limit counts what arrives);
 * - redirects are followed by hand, only to the same host, each looked up and checked again;
 * - a time limit, and a size limit: an announced length past it is refused before the body,
 *   and the body is written to a capped sink that stops the transfer as soon as it passes.
 *
 * Anything that cannot be checked (no peer reported, an unreadable answer) is refused.
 */
final readonly class GuardedRemoteFetcher implements RemoteFetcherInterface
{
    public const int MAX_REDIRECTS = 3;

    public const float CONNECT_TIMEOUT = 3.0;

    public const float TIMEOUT = 8.0;

    private const array REDIRECTS = [301, 302, 303, 307, 308];

    private const string USER_AGENT = 'Mozilla/5.0 (compatible; ForumMediaProxy/1.0)';

    private Client $client;

    /**
     * @param  callable|null  $handler  the Guzzle handler (curl by default; tests pass their own)
     */
    public function __construct(
        private HostResolverInterface $resolver,
        ?callable $handler = null,
    ) {
        $this->client = new Client(['handler' => HandlerStack::create($handler ?? new CurlHandler)]);
    }

    public function fetch(RemoteUrl $url, int $maxBytes, string $accept): RemoteResourceDTO
    {
        $current = $url;

        for ($hop = 0; ; $hop++) {
            $pinned = $this->publicAddressOf($current->host);
            $response = $this->send($current, $pinned, $maxBytes, $accept);

            if (in_array($response->getStatusCode(), self::REDIRECTS, true)) {
                if ($hop >= self::MAX_REDIRECTS) {
                    throw RemoteFetchException::because(RemoteFetchException::TOO_MANY_REDIRECTS, "Too many redirects from {$url->value}");
                }

                $next = $current->resolve($response->getHeaderLine('Location'));
                if (! $next instanceof RemoteUrl || $next->host !== $current->host) {
                    throw RemoteFetchException::because(RemoteFetchException::REDIRECT_ELSEWHERE, "{$current->value} redirects elsewhere");
                }
                $current = $next;

                continue;
            }

            if ($response->getStatusCode() !== 200) {
                throw RemoteFetchException::because(RemoteFetchException::HTTP_STATUS, "{$current->value} answered {$response->getStatusCode()}");
            }

            $bytes = (string) $response->getBody();
            if (strlen($bytes) > $maxBytes) {
                throw RemoteFetchException::because(RemoteFetchException::TOO_LARGE, "{$current->value} is larger than {$maxBytes} bytes");
            }

            return new RemoteResourceDTO($bytes, $current);
        }
    }

    /**
     * The address to connect to: the first one found, once every one is known to be public.
     *
     * @throws RemoteFetchException
     */
    private function publicAddressOf(string $host): IpAddress
    {
        $addresses = $this->resolver->addresses($host);
        if ($addresses === []) {
            throw RemoteFetchException::because(RemoteFetchException::UNRESOLVABLE, "{$host} has no address");
        }

        foreach ($addresses as $address) {
            if (! $address->isPublic()) {
                throw RemoteFetchException::because(RemoteFetchException::NOT_PUBLIC, "{$host} resolves to {$address->value}");
            }
        }

        return $addresses[0];
    }

    /**
     * @throws RemoteFetchException
     */
    private function send(RemoteUrl $url, IpAddress $pinned, int $maxBytes, string $accept): ResponseInterface
    {
        $peer = null;
        $sink = new CappedStream($maxBytes);

        try {
            $response = $this->client->request('GET', $url->value, [
                'allow_redirects' => false,
                'http_errors' => false,
                'decode_content' => false,
                'verify' => true,
                'connect_timeout' => self::CONNECT_TIMEOUT,
                'timeout' => self::TIMEOUT,
                'headers' => [
                    'Accept' => $accept,
                    'Accept-Encoding' => 'identity',
                    'User-Agent' => self::USER_AGENT,
                ],
                'protocols' => ['https'],
                // Set, so the proxy of the environment is never used
                'proxy' => '',
                'sink' => $sink,
                'on_headers' => static function (ResponseInterface $response) use ($maxBytes): void {
                    $announced = $response->getHeaderLine('Content-Length');
                    if (ctype_digit($announced) && (int) $announced > $maxBytes) {
                        throw RemoteFetchException::because(RemoteFetchException::TOO_LARGE, "Announced {$announced} bytes");
                    }
                },
                'curl' => [
                    CURLOPT_RESOLVE => ["{$url->host}:443:{$pinned->forCurlResolve()}"],
                ],
                'on_stats' => static function (TransferStats $stats) use (&$peer): void {
                    $ip = $stats->getHandlerStat('primary_ip');
                    $peer = is_string($ip) ? $ip : null;
                },
            ]);
        } catch (RequestException $exception) {
            $tooLarge = $sink->exceeded()
                || ($exception->getPrevious() instanceof RemoteFetchException && $exception->getPrevious()->reason === RemoteFetchException::TOO_LARGE);

            throw RemoteFetchException::because(
                $tooLarge ? RemoteFetchException::TOO_LARGE : RemoteFetchException::UNAVAILABLE,
                "{$url->value}: {$exception->getMessage()}",
            );
        } catch (GuzzleException $exception) {
            throw RemoteFetchException::because(RemoteFetchException::UNAVAILABLE, "{$url->value}: {$exception->getMessage()}");
        }

        // The connection must have gone where the check said; if curl cannot tell, refuse
        if (! $pinned->equals(IpAddress::fromString((string) $peer))) {
            throw RemoteFetchException::because(RemoteFetchException::CONNECTION_MISMATCH, "{$url->value} connected to ".($peer ?? 'an unknown address'));
        }

        return $response;
    }
}
