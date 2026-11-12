<?php

declare(strict_types=1);

namespace Modules\Forum\Application\Contracts;

use Modules\Forum\Application\DTOs\RemoteResourceDTO;
use Modules\Forum\Domain\Exceptions\RemoteFetchException;
use Modules\Forum\Domain\ValueObjects\RemoteUrl;

/**
 * Fetches a file from another site without letting the address reach the server's own
 * network: only public addresses (checked when the host is looked up and again on the
 * connection actually made), redirects only within the same host, a time limit and a size
 * limit. Whether the host may be fetched from at all is the caller's decision.
 */
interface RemoteFetcherInterface
{
    /**
     * @param  int  $maxBytes  larger answers are refused, also while they arrive
     * @param  string  $accept  the Accept header
     *
     * @throws RemoteFetchException
     */
    public function fetch(RemoteUrl $url, int $maxBytes, string $accept): RemoteResourceDTO;
}
