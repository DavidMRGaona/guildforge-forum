<?php

declare(strict_types=1);

namespace Modules\Forum\Domain\ValueObjects;

/**
 * An IPv4 or IPv6 address in its canonical spelling, and whether it is on the public internet.
 *
 * Public is decided by allowing, not by denying: IPv4 outside every special-purpose range, and
 * IPv6 only in global unicast (2000::/3) outside its special-purpose ranges. So loopback,
 * private, link-local (cloud metadata included), shared (CGNAT), unique local (ULA), multicast,
 * documentation, translation (NAT64, 6to4, Teredo) and IPv4-mapped addresses are never public,
 * nor anything a future registry assigns outside global unicast.
 */
final readonly class IpAddress
{
    /** Special-purpose IPv4 ranges (IANA registry), none of them reachable as a public host */
    private const array NON_PUBLIC_V4 = [
        '0.0.0.0/8',
        '10.0.0.0/8',
        '100.64.0.0/10',
        '127.0.0.0/8',
        '169.254.0.0/16',
        '172.16.0.0/12',
        '192.0.0.0/24',
        '192.0.2.0/24',
        '192.31.196.0/24',
        '192.52.193.0/24',
        '192.88.99.0/24',
        '192.168.0.0/16',
        '192.175.48.0/24',
        '198.18.0.0/15',
        '198.51.100.0/24',
        '203.0.113.0/24',
        '224.0.0.0/4',
        '240.0.0.0/4',
    ];

    private const string GLOBAL_UNICAST_V6 = '2000::/3';

    /** Special-purpose ranges inside IPv6 global unicast */
    private const array NON_PUBLIC_V6 = [
        // IETF protocol assignments, Teredo included
        '2001::/23',
        '2001:db8::/32',
        // 6to4: the IPv4 address inside could be anything
        '2002::/16',
        // Documentation
        '3fff::/20',
    ];

    /** Dotted decimal without leading zeros, so «0177.0.0.1» is not read as anything */
    private const string V4_PATTERN = '/^(0|[1-9]\d{0,2})(\.(0|[1-9]\d{0,2})){3}\z/';

    private function __construct(
        public string $value,
        private string $packed,
    ) {}

    /**
     * The address, or null when the value is not one written plainly (no names, no short or
     * decimal forms, no zone index, no brackets or port).
     */
    public static function fromString(string $value): ?self
    {
        $isV4 = preg_match(self::V4_PATTERN, $value) === 1 && filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;
        $isV6 = ! $isV4 && str_contains($value, ':') && filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false;

        if (! $isV4 && ! $isV6) {
            return null;
        }

        $packed = @inet_pton($value);
        if ($packed === false) {
            return null;
        }

        $canonical = inet_ntop($packed);

        return $canonical === false ? null : new self($canonical, $packed);
    }

    public function isV6(): bool
    {
        return strlen($this->packed) === 16;
    }

    public function isPublic(): bool
    {
        if (! $this->isV6()) {
            return ! $this->inAny(self::NON_PUBLIC_V4);
        }

        return $this->in(self::GLOBAL_UNICAST_V6) && ! $this->inAny(self::NON_PUBLIC_V6);
    }

    public function equals(?self $other): bool
    {
        return $other instanceof self && $other->packed === $this->packed;
    }

    /**
     * As curl takes it in a host:port:address pin: IPv6 between brackets.
     */
    public function forCurlResolve(): string
    {
        return $this->isV6() ? "[{$this->value}]" : $this->value;
    }

    /**
     * @param  list<string>  $ranges
     */
    private function inAny(array $ranges): bool
    {
        return array_any($ranges, fn ($range) => $this->in($range));
    }

    private function in(string $range): bool
    {
        [$network, $bits] = explode('/', $range);
        $packedNetwork = inet_pton($network);
        if ($packedNetwork === false || strlen($packedNetwork) !== strlen($this->packed)) {
            return false;
        }

        $bits = (int) $bits;
        $bytes = intdiv($bits, 8);
        if (substr($this->packed, 0, $bytes) !== substr($packedNetwork, 0, $bytes)) {
            return false;
        }

        $rest = $bits % 8;
        if ($rest === 0) {
            return true;
        }

        $mask = (0xFF << (8 - $rest)) & 0xFF;

        return (ord($this->packed[$bytes]) & $mask) === (ord($packedNetwork[$bytes]) & $mask);
    }
}
