<?php

declare(strict_types=1);

namespace Modules\Forum\Application\Services;

use App\Application\DTOs\ImageOptimizationSettingsDTO;
use App\Application\Services\ImageOptimizationServiceInterface;
use finfo;
use Modules\Forum\Application\Contracts\CharacterPortraitStorageInterface;
use Modules\Forum\Application\Contracts\ForumSettingsInterface;
use Modules\Forum\Domain\Enums\PostImageType;
use Modules\Forum\Domain\Exceptions\InvalidCharacterException;
use RuntimeException;

/**
 * Stores a character's portrait in the format it came, one of those the forum settings allow
 * for images (never SVG), reduced to MAX_SIDE. The type is read from the bytes. The request has
 * already checked the size.
 */
final readonly class CharacterPortraitUploader
{
    /** Portraits are shown small: this side is plenty */
    public const int MAX_SIDE = 512;

    public function __construct(
        private ImageOptimizationServiceInterface $optimizer,
        private CharacterPortraitStorageInterface $storage,
        private ForumSettingsInterface $settings,
    ) {}

    /**
     * @return string the stored path
     *
     * @throws InvalidCharacterException when the bytes are not an allowed image
     * @throws RuntimeException when it could not be stored
     */
    public function upload(string $contents): string
    {
        $type = $this->detect($contents);
        if (! $type instanceof PostImageType || ! in_array($type, $this->settings->imageTypes(), true)) {
            throw InvalidCharacterException::because(InvalidCharacterException::PORTRAIT, 'portrait', 'This image format is not allowed');
        }

        $optimized = $this->optimizer->optimize($contents, $type->mimeType(), ImageOptimizationSettingsDTO::withOverrides([
            'maxWidth' => self::MAX_SIDE,
            'maxHeight' => self::MAX_SIDE,
            'format' => null,
        ]));

        // Whatever the optimizer returned must still be the same kind of image
        return $this->storage->store($this->detect($optimized) === $type ? $optimized : $contents, $type->extension());
    }

    private function detect(string $contents): ?PostImageType
    {
        $mimeType = (new finfo(FILEINFO_MIME_TYPE))->buffer($contents);

        return is_string($mimeType) ? PostImageType::fromMimeType($mimeType) : null;
    }
}
