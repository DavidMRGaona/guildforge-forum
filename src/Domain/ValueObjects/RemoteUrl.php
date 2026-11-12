<?php

declare(strict_types=1);

namespace Modules\Forum\Domain\ValueObjects;

/**
 * An address the forum may fetch from: https on the default port, a host name with a
 * top-level domain (never an IP address, so the host list is what decides), no credentials,
 * no fragment, and a path and query of plain URL characters only. Read with a strict pattern
 * instead of parse_url(), so nothing in it can be taken one way here and another by curl or a
 * browser («https://a.example\@b.example», spaces, control characters).
 */
final readonly class RemoteUrl
{
    public const int MAX_LENGTH = 2048;

    private const string PATTERN = '#^https://([A-Za-z0-9.-]+)(?::443)?(/[A-Za-z0-9\-._~:/?\[\]@!$&\'()*+,;=%]*)?$#';

    private const string LABEL = '[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?';

    private function __construct(
        public string $value,
        public string $host,
        private string $pathAndQuery,
    ) {}

    public static function tryFrom(string $value): ?self
    {
        if ($value === '' || strlen($value) > self::MAX_LENGTH || preg_match(self::PATTERN, $value, $parts) !== 1) {
            return null;
        }

        $host = strtolower($parts[1]);
        if (! self::isHostName($host)) {
            return null;
        }

        $pathAndQuery = ($parts[2] ?? '') === '' ? '/' : $parts[2];

        return new self("https://{$host}{$pathAndQuery}", $host, $pathAndQuery);
    }

    /**
     * Whether the value is a host name in lowercase with at least two labels, the last one not
     * a number (so no IPv4 address in any spelling).
     */
    public static function isHostName(string $value): bool
    {
        return strlen($value) <= 253
            && preg_match('/^(?:'.self::LABEL.'\.)+[a-z](?:[a-z0-9-]{0,61}[a-z0-9])?$/', $value) === 1;
    }

    public function pathAndQuery(): string
    {
        return $this->pathAndQuery;
    }

    /**
     * Whether the host is one of the listed domains or a subdomain of one.
     *
     * @param  list<string>  $domains  host names in lowercase
     */
    public function hostIsIn(array $domains): bool
    {
        return array_any($domains, fn ($domain) => $this->host === $domain || str_ends_with($this->host, ".{$domain}"));
    }

    /**
     * Where a redirect's Location points from this address, or null when it is not an address
     * the forum may fetch from. Whether it may follow it (same host) is up to the caller.
     */
    public function resolve(string $location): ?self
    {
        if ($location === '') {
            return null;
        }

        if (str_starts_with($location, '//')) {
            return self::tryFrom('https:'.$location);
        }

        if (preg_match('#^[A-Za-z][A-Za-z0-9+.-]*:#', $location) === 1) {
            return self::tryFrom($location);
        }

        if (str_starts_with($location, '/')) {
            return self::tryFrom("https://{$this->host}".$this->withoutDotSegments($location));
        }

        $path = strtok($this->pathAndQuery, '?');
        $directory = substr((string) $path, 0, (int) strrpos((string) $path, '/') + 1);

        return self::tryFrom("https://{$this->host}".$this->withoutDotSegments($directory.$location));
    }

    /**
     * The path with «.» and «..» segments worked out (RFC 3986, 5.2.4), the query untouched.
     */
    private function withoutDotSegments(string $pathAndQuery): string
    {
        $query = '';
        $mark = strpos($pathAndQuery, '?');
        if ($mark !== false) {
            $query = substr($pathAndQuery, $mark);
            $pathAndQuery = substr($pathAndQuery, 0, $mark);
        }

        $output = [];
        $segments = explode('/', $pathAndQuery);
        $last = count($segments) - 1;
        foreach ($segments as $index => $segment) {
            if ($segment === '..') {
                if (count($output) > 1) {
                    array_pop($output);
                }
            } elseif ($segment !== '.') {
                $output[] = $segment;
            }

            if (($segment === '.' || $segment === '..') && $index === $last) {
                $output[] = '';
            }
        }

        $path = implode('/', $output);

        return (str_starts_with($path, '/') ? $path : '/'.$path).$query;
    }
}
