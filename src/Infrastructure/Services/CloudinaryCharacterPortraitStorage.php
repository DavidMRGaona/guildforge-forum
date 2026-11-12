<?php

declare(strict_types=1);

namespace Modules\Forum\Infrastructure\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Forum\Application\Contracts\CharacterPortraitStorageInterface;
use Modules\Forum\Domain\Entities\Character;
use RuntimeException;
use Throwable;

/**
 * Portraits on the site's image disk (Cloudinary), at forum/characters/<year>/<month>/<uuid>.<ext>.
 * Files are never deleted here: a replaced portrait becomes unused and the media library
 * removes it.
 */
final class CloudinaryCharacterPortraitStorage implements CharacterPortraitStorageInterface
{
    private const string DISK = 'images';

    private const array EXTENSIONS = ['jpg', 'png', 'webp', 'gif'];

    public function store(string $contents, string $extension): string
    {
        if (! in_array($extension, self::EXTENSIONS, true)) {
            throw new RuntimeException("Portraits are JPEG, PNG, WebP or GIF ('{$extension}' given).");
        }

        $path = Character::PORTRAIT_FOLDER.CarbonImmutable::now()->format('Y/m').'/'.Str::uuid()->toString().'.'.$extension;

        if (Storage::disk(self::DISK)->put($path, $contents) === false) {
            throw new RuntimeException('The portrait could not be stored.');
        }

        return $path;
    }

    public function url(string $path): ?string
    {
        try {
            return Storage::disk(self::DISK)->url($path);
        } catch (Throwable $exception) {
            Log::warning('Forum: the address of a portrait could not be built.', ['path' => $path, 'exception' => $exception->getMessage()]);

            return null;
        }
    }
}
