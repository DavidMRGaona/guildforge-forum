<?php

declare(strict_types=1);

namespace Modules\Forum\Infrastructure\Fetching;

use GuzzleHttp\Psr7\StreamDecoratorTrait;
use GuzzleHttp\Psr7\Utils;
use Psr\Http\Message\StreamInterface;

/**
 * Where a fetched body is written, in memory, up to a limit: a write that would pass it takes
 * nothing, which makes curl stop the transfer at once (a short write aborts it).
 */
final class CappedStream implements StreamInterface
{
    use StreamDecoratorTrait;

    /** The stream the trait decorates */
    private StreamInterface $stream;

    private bool $exceeded = false;

    private int $written = 0;

    public function __construct(
        private readonly int $limit,
    ) {
        $this->stream = Utils::streamFor(Utils::tryFopen('php://temp', 'w+'));
    }

    public function write(string $string): int
    {
        if ($this->written + strlen($string) > $this->limit) {
            $this->exceeded = true;

            return 0;
        }

        $written = $this->stream->write($string);
        $this->written += $written;

        return $written;
    }

    /** Whether something was refused for going past the limit */
    public function exceeded(): bool
    {
        return $this->exceeded;
    }
}
