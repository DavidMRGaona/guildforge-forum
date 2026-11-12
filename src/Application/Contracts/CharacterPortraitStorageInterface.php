<?php

declare(strict_types=1);

namespace Modules\Forum\Application\Contracts;

use RuntimeException;

/**
 * Where character portraits are kept: the site's image storage, under the folder the forum's
 * portrait media source claims (forum/characters/).
 */
interface CharacterPortraitStorageInterface
{
    /**
     * Stores a portrait already checked and returns its stored path.
     *
     * @param  string  $extension  jpg, png, webp or gif
     *
     * @throws RuntimeException when it could not be stored
     */
    public function store(string $contents, string $extension): string;

    /** The delivery URL of a stored path, or null when it cannot be built */
    public function url(string $path): ?string;
}
