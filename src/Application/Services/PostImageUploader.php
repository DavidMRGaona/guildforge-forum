<?php

declare(strict_types=1);

namespace Modules\Forum\Application\Services;

use App\Application\DTOs\ImageOptimizationSettingsDTO;
use App\Application\Services\ImageOptimizationServiceInterface;
use finfo;
use Modules\Forum\Application\Content\Nodes\ImageNode;
use Modules\Forum\Application\Contracts\ForumSettingsInterface;
use Modules\Forum\Application\Contracts\PostImageStorageInterface;
use Modules\Forum\Application\Images\ImageMetadataStripper;
use Modules\Forum\Domain\Enums\PostImageType;
use Modules\Forum\Domain\Exceptions\InvalidDocumentException;
use RuntimeException;

/**
 * Stores an image someone adds to a post, in the format it came, which must be one the forum
 * settings allow (never SVG), resized to the settings' longest side (animated ones keep their
 * bytes) and without its metadata (camera, place, comments). The type is read from the bytes,
 * not from the file name. The caller has already checked the size.
 */
final readonly class PostImageUploader
{
    /** Larger sources are refused before decoding, so a small file cannot unpack into a huge image */
    public const int MAX_SOURCE_DIMENSION = 8000;

    public function __construct(
        private ImageOptimizationServiceInterface $optimizer,
        private PostImageStorageInterface $storage,
        private ForumSettingsInterface $settings,
        private ImageMetadataStripper $stripper,
    ) {}

    /**
     * @param  string  $folder  ImageNode::FOLDER for uploads, ImageNode::EXTERNAL_FOLDER for copies of images from other sites
     * @return array{path: string, url: string|null}
     *
     * @throws InvalidDocumentException when the bytes are not an allowed image, or one whose metadata cannot be taken out
     * @throws RuntimeException when the image could not be stored
     */
    public function upload(string $contents, string $folder = ImageNode::FOLDER): array
    {
        $type = $this->typeOf($contents);
        $side = $this->settings->imageMaxDimension();

        $optimized = $this->optimizer->optimize($contents, $type->mimeType(), ImageOptimizationSettingsDTO::withOverrides([
            'maxWidth' => $side,
            'maxHeight' => $side,
            // Kept in its format, so the stored extension matches the bytes
            'format' => null,
        ]));

        // Whatever the optimizer returned must still be the same kind of image
        $stored = $this->stripper->strip($this->detect($optimized) === $type ? $optimized : $contents, $type)
            ?? throw InvalidDocumentException::because(InvalidDocumentException::INVALID_IMAGE, '', 'The image could not be read');
        $path = $this->storage->store($stored, $type->extension(), $folder);

        return ['path' => $path, 'url' => $this->storage->url($path)];
    }

    /**
     * @throws InvalidDocumentException
     */
    private function typeOf(string $contents): PostImageType
    {
        $type = $this->detect($contents);

        if (! $type instanceof PostImageType || ! in_array($type, $this->settings->imageTypes(), true)) {
            throw InvalidDocumentException::because(InvalidDocumentException::INVALID_IMAGE, '', 'This image format is not allowed');
        }

        return $type;
    }

    private function detect(string $contents): ?PostImageType
    {
        $mimeType = (new finfo(FILEINFO_MIME_TYPE))->buffer($contents);

        return is_string($mimeType) ? PostImageType::fromMimeType($mimeType) : null;
    }
}
