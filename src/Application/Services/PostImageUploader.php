<?php

declare(strict_types=1);

namespace Modules\Forum\Application\Services;

use App\Application\DTOs\ImageOptimizationSettingsDTO;
use App\Application\Services\ImageOptimizationServiceInterface;
use finfo;
use Modules\Forum\Application\Contracts\PostImageStorageInterface;
use Modules\Forum\Domain\Exceptions\InvalidDocumentException;
use RuntimeException;

/**
 * Stores an image someone adds to a post: resized to 2000 px at most (animated ones keep their
 * bytes) in the format it came, which must be JPEG, PNG, WebP or GIF, never SVG. The type is
 * read from the bytes, not from the file name. The request has already checked the size.
 */
final readonly class PostImageUploader
{
    public const int MAX_KILOBYTES = 4096;

    public const int MAX_DIMENSION = 2000;

    /** Larger sources are refused before decoding, so a small file cannot unpack into a huge image */
    public const int MAX_SOURCE_DIMENSION = 8000;

    /** Allowed types and the extension each is stored with */
    public const array TYPES = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
    ];

    public function __construct(
        private ImageOptimizationServiceInterface $optimizer,
        private PostImageStorageInterface $storage,
    ) {}

    /**
     * @return array{path: string, url: string|null}
     *
     * @throws InvalidDocumentException when the bytes are not an allowed image
     * @throws RuntimeException when the image could not be stored
     */
    public function upload(string $contents): array
    {
        $type = $this->typeOf($contents);

        $optimized = $this->optimizer->optimize($contents, $type, ImageOptimizationSettingsDTO::withOverrides([
            'maxWidth' => self::MAX_DIMENSION,
            'maxHeight' => self::MAX_DIMENSION,
            // Kept in its format, so the stored extension matches the bytes
            'format' => null,
        ]));

        // Whatever the optimizer returned must still be the same kind of image
        $stored = $this->typeOf($optimized) === $type ? $optimized : $contents;
        $path = $this->storage->store($stored, self::TYPES[$type]);

        return ['path' => $path, 'url' => $this->storage->url($path)];
    }

    /**
     * @throws InvalidDocumentException
     */
    private function typeOf(string $contents): string
    {
        $type = (new finfo(FILEINFO_MIME_TYPE))->buffer($contents);

        if (! is_string($type) || ! isset(self::TYPES[$type])) {
            throw InvalidDocumentException::because(InvalidDocumentException::INVALID_IMAGE, '', 'Images are JPEG, PNG, WebP or GIF');
        }

        return $type;
    }
}
