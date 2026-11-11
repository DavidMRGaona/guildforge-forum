<?php

declare(strict_types=1);

namespace Modules\Forum\Domain\Enums;

/**
 * The image formats a post may hold. Never SVG: it can carry scripts.
 */
enum PostImageType: string
{
    case Jpeg = 'jpeg';
    case Png = 'png';
    case Webp = 'webp';
    case Gif = 'gif';

    public function mimeType(): string
    {
        return 'image/'.$this->value;
    }

    /** The extension it is stored with */
    public function extension(): string
    {
        return $this === self::Jpeg ? 'jpg' : $this->value;
    }

    public function label(): string
    {
        return match ($this) {
            self::Jpeg => 'JPEG',
            self::Png => 'PNG',
            self::Webp => 'WebP',
            self::Gif => 'GIF',
        };
    }

    public static function fromMimeType(string $mimeType): ?self
    {
        foreach (self::cases() as $type) {
            if ($type->mimeType() === $mimeType) {
                return $type;
            }
        }

        return null;
    }
}
