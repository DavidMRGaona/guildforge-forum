<?php

declare(strict_types=1);

namespace Modules\Forum\Application\Contracts;

use RuntimeException;

/**
 * Where post images are kept: the site's image storage, under the folder the forum's media
 * source claims.
 */
interface PostImageStorageInterface
{
    /**
     * Stores an image already checked and returns its stored path, the value image nodes hold.
     *
     * @param  string  $extension  jpg, png, webp or gif
     *
     * @throws RuntimeException when it could not be stored
     */
    public function store(string $contents, string $extension): string;

    /** The delivery URL of a stored path, or null when it cannot be built */
    public function url(string $path): ?string;
}
