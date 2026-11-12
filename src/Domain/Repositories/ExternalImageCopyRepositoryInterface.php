<?php

declare(strict_types=1);

namespace Modules\Forum\Domain\Repositories;

use Carbon\CarbonImmutable;

/**
 * Which stored file is the forum's copy of an image from another site, one per address.
 */
interface ExternalImageCopyRepositoryInterface
{
    /** The stored path of the copy of the address, or null when there is none */
    public function pathFor(string $url): ?string;

    /**
     * The copies of several addresses.
     *
     * @param  list<string>  $urls
     * @return array<string, string> stored paths by address
     */
    public function pathsFor(array $urls): array;

    /**
     * Records the copy of an address and returns the path kept for it: this one, or the one
     * another request recorded first for the same address.
     */
    public function save(string $url, string $path, CarbonImmutable $fetchedAt): string;

    /**
     * The addresses whose copy is this stored file.
     *
     * @return list<string>
     */
    public function urlsOf(string $path): array;
}
