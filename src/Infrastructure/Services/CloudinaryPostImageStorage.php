<?php

declare(strict_types=1);

namespace Modules\Forum\Infrastructure\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Forum\Application\Content\Nodes\ImageNode;
use Modules\Forum\Application\Contracts\PostImageStorageInterface;
use RuntimeException;
use Throwable;

/**
 * Post images on the site's image disk (Cloudinary), at forum/posts/<year>/<month>/<uuid>.<ext>:
 * the folder the forum's media source claims, so the media library knows where they come from.
 * Files are never deleted here: an image stays in use while any version of a post shows it,
 * and the media library removes the ones nobody uses.
 */
final class CloudinaryPostImageStorage implements PostImageStorageInterface
{
    private const string DISK = 'images';

    private const array EXTENSIONS = ['jpg', 'png', 'webp', 'gif'];

    public function store(string $contents, string $extension): string
    {
        if (! in_array($extension, self::EXTENSIONS, true)) {
            throw new RuntimeException("Post images are JPEG, PNG, WebP or GIF ('{$extension}' given).");
        }

        $path = ImageNode::FOLDER.CarbonImmutable::now()->format('Y/m').'/'.Str::uuid()->toString().'.'.$extension;

        if (Storage::disk(self::DISK)->put($path, $contents) === false) {
            throw new RuntimeException('The image could not be stored.');
        }

        return $path;
    }

    public function url(string $path): ?string
    {
        try {
            return Storage::disk(self::DISK)->url($path);
        } catch (Throwable $exception) {
            Log::warning('Forum: the address of a post image could not be built.', ['path' => $path, 'exception' => $exception->getMessage()]);

            return null;
        }
    }
}
