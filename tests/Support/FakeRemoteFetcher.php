<?php

declare(strict_types=1);

namespace Modules\Forum\Tests\Support;

use Modules\Forum\Application\Contracts\RemoteFetcherInterface;
use Modules\Forum\Application\DTOs\RemoteResourceDTO;
use Modules\Forum\Domain\Exceptions\RemoteFetchException;
use Modules\Forum\Domain\ValueObjects\RemoteUrl;

/**
 * Answers fetches from a table of addresses, and remembers what was asked.
 */
final class FakeRemoteFetcher implements RemoteFetcherInterface
{
    /** @var array<string, string|RemoteFetchException> */
    private array $answers = [];

    /** @var list<array{url: string, maxBytes: int}> */
    public array $fetched = [];

    public function answer(string $url, string|RemoteFetchException $bytes): self
    {
        $this->answers[$url] = $bytes;

        return $this;
    }

    public function fetch(RemoteUrl $url, int $maxBytes, string $accept): RemoteResourceDTO
    {
        $this->fetched[] = ['url' => $url->value, 'maxBytes' => $maxBytes];
        $answer = $this->answers[$url->value] ?? RemoteFetchException::because(RemoteFetchException::HTTP_STATUS, 'Not in the fake');

        if ($answer instanceof RemoteFetchException) {
            throw $answer;
        }

        return new RemoteResourceDTO($answer, $url);
    }
}
