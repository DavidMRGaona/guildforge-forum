<?php

declare(strict_types=1);

namespace Modules\Forum\Application\Images;

use GdImage;
use Modules\Forum\Domain\Enums\PostImageType;

/**
 * Takes out of an image what it says besides its pixels: EXIF (camera, time, GPS position),
 * XMP, IPTC, comments and text chunks. It works on the file's structure, without decoding, so
 * animations and quality stay as they are, and it keeps what drawing needs (colour profiles,
 * transparency, animation control).
 *
 * A JPEG turned by its EXIF orientation is turned upright first (re-encoded with GD, which
 * writes no metadata), or it would show sideways once the orientation goes.
 *
 * Anything it cannot read to the end gives null: the caller refuses the image rather than
 * keeping a file whose metadata could not be taken out.
 */
final class ImageMetadataStripper
{
    private const string PNG_SIGNATURE = "\x89PNG\r\n\x1A\n";

    /** Ancillary PNG chunks drawing needs; every other ancillary chunk goes */
    private const array PNG_KEPT_ANCILLARY = ['tRNS', 'gAMA', 'cHRM', 'sRGB', 'iCCP', 'sBIT', 'bKGD', 'pHYs', 'acTL', 'fcTL', 'fdAT', 'cICP', 'mDCV', 'cLLI'];

    private const array PNG_CRITICAL = ['IHDR', 'PLTE', 'IDAT', 'IEND'];

    /** WebP chunks of the image itself; EXIF, XMP and unknown chunks go */
    private const array WEBP_KEPT = ['VP8 ', 'VP8L', 'VP8X', 'ALPH', 'ANIM', 'ANMF', 'ICCP'];

    private const int WEBP_FLAG_EXIF = 0x08;

    private const int WEBP_FLAG_XMP = 0x04;

    /** GIF application blocks that control looping; any other application block goes */
    private const array GIF_KEPT_APPLICATIONS = ['NETSCAPE2.0', 'ANIMEXTS1.0'];

    private const int JPEG_QUALITY = 90;

    public function strip(string $bytes, PostImageType $type): ?string
    {
        return match ($type) {
            PostImageType::Jpeg => $this->jpeg($bytes),
            PostImageType::Png => $this->png($bytes),
            PostImageType::Webp => $this->webp($bytes),
            PostImageType::Gif => $this->gif($bytes),
        };
    }

    private function jpeg(string $bytes): ?string
    {
        if (! str_starts_with($bytes, "\xFF\xD8")) {
            return null;
        }

        $kept = "\xFF\xD8";
        $orientation = 1;
        $length = strlen($bytes);
        $offset = 2;

        while (true) {
            if ($offset + 1 >= $length || $bytes[$offset] !== "\xFF") {
                return null;
            }
            // Fill bytes before a marker
            while ($offset + 1 < $length && $bytes[$offset + 1] === "\xFF") {
                $offset++;
            }
            if ($offset + 1 >= $length) {
                return null;
            }

            $marker = ord($bytes[$offset + 1]);

            // Start of scan: the compressed image and whatever follows it, as it is
            if ($marker === 0xDA) {
                $kept .= substr($bytes, $offset);

                break;
            }

            // End of image before any scan: nothing to draw
            if ($marker === 0xD9) {
                return null;
            }

            // Markers without a length
            if ($marker === 0x01 || ($marker >= 0xD0 && $marker <= 0xD7)) {
                $kept .= substr($bytes, $offset, 2);
                $offset += 2;

                continue;
            }

            if ($offset + 4 > $length) {
                return null;
            }
            $size = (int) (unpack('n', substr($bytes, $offset + 2, 2))[1] ?? 0);
            if ($size < 2 || $offset + 2 + $size > $length) {
                return null;
            }

            $segment = substr($bytes, $offset, 2 + $size);
            $payload = substr($segment, 4);

            if ($marker === 0xE1 && str_starts_with($payload, "Exif\0\0")) {
                $orientation = $this->exifOrientation(substr($payload, 6)) ?? $orientation;
            }

            if (! $this->dropsJpegSegment($marker, $payload)) {
                $kept .= $segment;
            }

            $offset += 2 + $size;
        }

        return $orientation === 1 ? $kept : $this->upright($kept, $orientation);
    }

    /**
     * Comments and every APPn segment except JFIF (APP0), the colour profile (APP2
     * ICC_PROFILE) and Adobe's colour transform (APP14).
     */
    private function dropsJpegSegment(int $marker, string $payload): bool
    {
        if ($marker === 0xFE) {
            return true;
        }
        if ($marker < 0xE1 || $marker > 0xEF) {
            return false;
        }

        return ($marker !== 0xE2 || ! str_starts_with($payload, "ICC_PROFILE\0"))
            && ($marker !== 0xEE || ! str_starts_with($payload, 'Adobe'));
    }

    /**
     * The orientation tag of IFD0 (1 to 8), or null when there is none or it cannot be read.
     */
    private function exifOrientation(string $tiff): ?int
    {
        $order = substr($tiff, 0, 2);
        if ($order !== 'II' && $order !== 'MM' || strlen($tiff) < 8) {
            return null;
        }
        $short = $order === 'II' ? 'v' : 'n';
        $long = $order === 'II' ? 'V' : 'N';

        $ifd = (int) (unpack($long, substr($tiff, 4, 4))[1] ?? 0);
        if ($ifd + 2 > strlen($tiff)) {
            return null;
        }

        $entries = (int) (unpack($short, substr($tiff, $ifd, 2))[1] ?? 0);
        for ($index = 0; $index < $entries; $index++) {
            $entry = $ifd + 2 + 12 * $index;
            if ($entry + 12 > strlen($tiff)) {
                return null;
            }
            if ((int) (unpack($short, substr($tiff, $entry, 2))[1] ?? 0) === 0x0112) {
                $value = (int) (unpack($short, substr($tiff, $entry + 8, 2))[1] ?? 0);

                return $value >= 1 && $value <= 8 ? $value : null;
            }
        }

        return null;
    }

    /**
     * The image turned as its orientation says, re-encoded without metadata.
     */
    private function upright(string $jpeg, int $orientation): ?string
    {
        $image = @imagecreatefromstring($jpeg);
        if (! $image instanceof GdImage) {
            return null;
        }

        $turned = match ($orientation) {
            2 => $this->flipped($image, IMG_FLIP_HORIZONTAL),
            3 => imagerotate($image, 180, 0),
            4 => $this->flipped($image, IMG_FLIP_VERTICAL),
            // Transpose: a quarter turn clockwise, then a mirror
            5 => $this->turnAndFlip($image, IMG_FLIP_HORIZONTAL),
            // GD turns counter-clockwise
            6 => imagerotate($image, 270, 0),
            // Transverse: a quarter turn clockwise, then upside down
            7 => $this->turnAndFlip($image, IMG_FLIP_VERTICAL),
            8 => imagerotate($image, 90, 0),
            default => $image,
        };
        if (! $turned instanceof GdImage) {
            return null;
        }

        ob_start();
        $written = imagejpeg($turned, null, self::JPEG_QUALITY);
        $encoded = (string) ob_get_clean();

        return $written && $encoded !== '' ? $encoded : null;
    }

    private function turnAndFlip(GdImage $image, int $flip): ?GdImage
    {
        $turned = imagerotate($image, 270, 0);

        return $turned instanceof GdImage ? $this->flipped($turned, $flip) : null;
    }

    private function flipped(GdImage $image, int $flip): GdImage
    {
        imageflip($image, $flip);

        return $image;
    }

    private function png(string $bytes): ?string
    {
        if (! str_starts_with($bytes, self::PNG_SIGNATURE)) {
            return null;
        }

        $kept = self::PNG_SIGNATURE;
        $length = strlen($bytes);
        $offset = strlen(self::PNG_SIGNATURE);

        while ($offset + 12 <= $length) {
            $size = (int) (unpack('N', substr($bytes, $offset, 4))[1] ?? 0);
            $type = substr($bytes, $offset + 4, 4);
            if (preg_match('/^[A-Za-z]{4}$/', $type) !== 1 || $offset + 12 + $size > $length) {
                return null;
            }

            $chunk = substr($bytes, $offset, 12 + $size);
            $offset += 12 + $size;

            $critical = ctype_upper($type[0]);
            if ($critical && ! in_array($type, self::PNG_CRITICAL, true)) {
                // A critical chunk this reader does not know: it cannot tell what it does
                return null;
            }

            if ($critical || in_array($type, self::PNG_KEPT_ANCILLARY, true)) {
                $kept .= $chunk;
            }

            if ($type === 'IEND') {
                return $kept;
            }
        }

        return null;
    }

    private function webp(string $bytes): ?string
    {
        if (strlen($bytes) < 12 || ! str_starts_with($bytes, 'RIFF') || substr($bytes, 8, 4) !== 'WEBP') {
            return null;
        }

        $declared = (int) (unpack('V', substr($bytes, 4, 4))[1] ?? 0);
        $length = min(strlen($bytes), 8 + $declared);
        $offset = 12;
        $chunks = [];
        $changed = false;

        while ($offset + 8 <= $length) {
            $fourCc = substr($bytes, $offset, 4);
            $size = (int) (unpack('V', substr($bytes, $offset + 4, 4))[1] ?? 0);
            $padded = $size + ($size % 2);
            if ($offset + 8 + $size > $length) {
                return null;
            }

            $chunk = substr($bytes, $offset, 8 + $padded);
            $offset += 8 + $padded;

            if (! in_array($fourCc, self::WEBP_KEPT, true)) {
                $changed = true;

                continue;
            }

            if ($fourCc === 'VP8X' && $size >= 1) {
                $flags = ord($chunk[8]);
                $cleared = $flags & ~(self::WEBP_FLAG_EXIF | self::WEBP_FLAG_XMP);
                if ($cleared !== $flags) {
                    $chunk[8] = chr($cleared);
                    $changed = true;
                }
            }

            $chunks[] = $chunk;
        }

        if ($chunks === [] || $offset < $length - 1) {
            return null;
        }

        if (! $changed) {
            return $bytes;
        }

        $body = 'WEBP'.implode('', $chunks);

        return 'RIFF'.pack('V', strlen($body)).$body;
    }

    private function gif(string $bytes): ?string
    {
        if (strlen($bytes) < 13 || (! str_starts_with($bytes, 'GIF87a') && ! str_starts_with($bytes, 'GIF89a'))) {
            return null;
        }

        $length = strlen($bytes);
        $flags = ord($bytes[10]);
        $offset = 13 + $this->gifColourTableSize($flags);
        if ($offset > $length) {
            return null;
        }
        $kept = substr($bytes, 0, $offset);
        $images = 0;

        while ($offset < $length) {
            $introducer = $bytes[$offset];

            if ($introducer === "\x3B") {
                return $images > 0 ? $kept."\x3B" : null;
            }

            if ($introducer === "\x2C") {
                if ($offset + 10 > $length) {
                    return null;
                }
                $tableEnd = $offset + 10 + $this->gifColourTableSize(ord($bytes[$offset + 9]));
                // The LZW code size, then the image data
                $end = $this->gifSubBlocksEnd($bytes, $tableEnd + 1);
                if ($end === null) {
                    return null;
                }
                $kept .= substr($bytes, $offset, $end - $offset);
                $offset = $end;
                $images++;

                continue;
            }

            if ($introducer !== "\x21" || $offset + 2 > $length) {
                return null;
            }

            $label = ord($bytes[$offset + 1]);
            $end = $this->gifSubBlocksEnd($bytes, $offset + 2);
            if ($end === null) {
                return null;
            }

            if ($this->keepsGifExtension($label, $bytes, $offset)) {
                $kept .= substr($bytes, $offset, $end - $offset);
            }
            $offset = $end;
        }

        return null;
    }

    private function gifColourTableSize(int $flags): int
    {
        return ($flags & 0x80) !== 0 ? 3 * (2 ** (($flags & 0x07) + 1)) : 0;
    }

    /**
     * Graphic control and plain text (drawing) and the looping application blocks; never
     * comments or other applications' data.
     */
    private function keepsGifExtension(int $label, string $bytes, int $offset): bool
    {
        if ($label === 0xF9 || $label === 0x01) {
            return true;
        }

        return $label === 0xFF
            && ord(($bytes[$offset + 2] ?? "\0")[0]) === 11
            && in_array(substr($bytes, $offset + 3, 11), self::GIF_KEPT_APPLICATIONS, true);
    }

    /**
     * Where a run of sub-blocks ends (after its empty block), or null when it is cut short.
     */
    private function gifSubBlocksEnd(string $bytes, int $offset): ?int
    {
        $length = strlen($bytes);

        while ($offset < $length) {
            $size = ord($bytes[$offset]);
            $offset += 1 + $size;
            if ($size === 0) {
                return $offset;
            }
        }

        return null;
    }
}
