<?php

declare(strict_types=1);

namespace Modules\Forum\Tests\Unit\Application\Images;

use GdImage;
use Modules\Forum\Application\Images\ImageMetadataStripper;
use Modules\Forum\Domain\Enums\PostImageType;
use PHPUnit\Framework\TestCase;

/**
 * Images lose what they say about who took them (EXIF with its GPS position, XMP, IPTC,
 * comments, text chunks) and keep what they need to be drawn the same.
 */
final class ImageMetadataStripperTest extends TestCase
{
    private const string SECRET = 'GPS-SECRET-40.4168N';

    private ImageMetadataStripper $stripper;

    protected function setUp(): void
    {
        parent::setUp();

        $this->stripper = new ImageMetadataStripper;
    }

    public function test_jpeg_loses_exif_xmp_iptc_and_comments_and_keeps_its_pixels(): void
    {
        $plain = $this->jpeg(40, 20);
        $tagged = $this->insertAfterSoi($plain, [
            $this->segment(0xE1, $this->exif(1)),
            $this->segment(0xE1, "http://ns.adobe.com/xap/1.0/\0<x:xmpmeta>".self::SECRET.'</x:xmpmeta>'),
            $this->segment(0xED, 'Photoshop 3.0'."\0".self::SECRET),
            $this->segment(0xFE, self::SECRET),
        ]);
        $this->assertStringContainsString(self::SECRET, $tagged);

        $stripped = $this->stripper->strip($tagged, PostImageType::Jpeg);

        $this->assertIsString($stripped);
        $this->assertStringNotContainsString(self::SECRET, $stripped);
        $this->assertStringNotContainsString('Exif', $stripped);
        $this->assertSame([40, 20], $this->dimensions($stripped));
    }

    public function test_jpeg_loses_what_hides_between_its_scans_and_after_its_end(): void
    {
        // A progressive JPEG has several scans: a comment between two of them, and bytes after
        // the end of the image, are not image data
        $image = imagecreatetruecolor(40, 20);
        $this->assertInstanceOf(GdImage::class, $image);
        imageinterlace($image, true);
        $progressive = $this->encodeJpeg($image);
        $second = strpos($progressive, "\xFF\xDA", (int) strpos($progressive, "\xFF\xDA") + 2);
        $this->assertIsInt($second, 'GD wrote a progressive JPEG');
        $tagged = substr($progressive, 0, $second).$this->segment(0xFE, self::SECRET).$this->segment(0xE1, "Exif\0\0".self::SECRET).substr($progressive, $second).self::SECRET;

        $stripped = $this->stripper->strip($tagged, PostImageType::Jpeg);

        $this->assertIsString($stripped);
        $this->assertStringNotContainsString(self::SECRET, $stripped);
        $this->assertStringEndsWith("\xFF\xD9", $stripped);
        $this->assertSame([40, 20], $this->dimensions($stripped));
        $this->assertInstanceOf(GdImage::class, imagecreatefromstring($stripped));
    }

    public function test_jpeg_keeps_its_colour_profile(): void
    {
        $tagged = $this->insertAfterSoi($this->jpeg(8, 8), [$this->segment(0xE2, "ICC_PROFILE\0\x01\x01profile-bytes")]);

        $stripped = $this->stripper->strip($tagged, PostImageType::Jpeg);

        $this->assertIsString($stripped);
        $this->assertStringContainsString('ICC_PROFILE', $stripped);
    }

    public function test_a_turned_jpeg_is_turned_upright_before_its_orientation_goes(): void
    {
        // Left half red, right half blue; orientation 6 means «turn 90° clockwise to show»
        $image = imagecreatetruecolor(40, 20);
        $this->assertInstanceOf(GdImage::class, $image);
        imagefilledrectangle($image, 0, 0, 19, 19, (int) imagecolorallocate($image, 255, 0, 0));
        imagefilledrectangle($image, 20, 0, 39, 19, (int) imagecolorallocate($image, 0, 0, 255));
        $tagged = $this->insertAfterSoi($this->encodeJpeg($image), [$this->segment(0xE1, $this->exif(6))]);

        $stripped = $this->stripper->strip($tagged, PostImageType::Jpeg);

        $this->assertIsString($stripped);
        $this->assertStringNotContainsString(self::SECRET, $stripped);
        $this->assertSame([20, 40], $this->dimensions($stripped));
        $upright = imagecreatefromstring($stripped);
        $this->assertInstanceOf(GdImage::class, $upright);
        // The left half goes to the top
        $this->assertGreaterThan(200, (imagecolorat($upright, 10, 5) >> 16) & 0xFF);
        $this->assertGreaterThan(200, imagecolorat($upright, 10, 35) & 0xFF);
    }

    public function test_png_loses_text_exif_and_time_chunks(): void
    {
        $image = imagecreatetruecolor(12, 6);
        $this->assertInstanceOf(GdImage::class, $image);
        ob_start();
        imagepng($image);
        $plain = (string) ob_get_clean();

        $tagged = $this->insertBeforeIend($plain, [
            $this->chunk('tEXt', "Comment\0".self::SECRET),
            $this->chunk('iTXt', "XML:com.adobe.xmp\0\0\0\0\0".self::SECRET),
            $this->chunk('zTXt', "Author\0\0".gzcompress(self::SECRET)),
            $this->chunk('eXIf', substr($this->exif(1), 6)),
            $this->chunk('tIME', "\x07\xEA\x0B\x0C\x0A\x00\x00"),
            $this->chunk('prVt', self::SECRET),
        ]);

        $stripped = $this->stripper->strip($tagged, PostImageType::Png);

        $this->assertIsString($stripped);
        $this->assertStringNotContainsString(self::SECRET, $stripped);
        foreach (['tEXt', 'iTXt', 'zTXt', 'eXIf', 'tIME', 'prVt'] as $type) {
            $this->assertStringNotContainsString($type, $stripped);
        }
        $this->assertSame([12, 6], $this->dimensions($stripped));
    }

    public function test_a_png_with_an_unknown_critical_chunk_is_refused(): void
    {
        $image = imagecreatetruecolor(4, 4);
        $this->assertInstanceOf(GdImage::class, $image);
        ob_start();
        imagepng($image);

        $this->assertNull($this->stripper->strip($this->insertBeforeIend((string) ob_get_clean(), [$this->chunk('ZZZZ', 'x')]), PostImageType::Png));
    }

    public function test_webp_loses_its_exif_and_xmp_chunks_and_their_flags(): void
    {
        $image = imagecreatetruecolor(10, 10);
        $this->assertInstanceOf(GdImage::class, $image);
        ob_start();
        imagewebp($image);
        $simple = (string) ob_get_clean();
        $bitstream = substr($simple, 12);

        // Extended format: VP8X with the EXIF and XMP flags, then the image, then the metadata
        $vp8x = $this->riffChunk('VP8X', chr(0x08 | 0x04)."\0\0\0".$this->uint24(9).$this->uint24(9));
        $body = 'WEBP'.$vp8x.$bitstream.$this->riffChunk('EXIF', substr($this->exif(1), 6)).$this->riffChunk('XMP ', self::SECRET);
        $tagged = 'RIFF'.pack('V', strlen($body)).$body;
        $this->assertSame([10, 10], $this->dimensions($tagged));

        $stripped = $this->stripper->strip($tagged, PostImageType::Webp);

        $this->assertIsString($stripped);
        $this->assertStringNotContainsString(self::SECRET, $stripped);
        $this->assertStringNotContainsString('EXIF', $stripped);
        $this->assertSame(strlen($stripped) - 8, unpack('V', substr($stripped, 4, 4))[1] ?? null);
        $this->assertSame(0, ord($stripped[20]) & (0x08 | 0x04));
        $this->assertSame([10, 10], $this->dimensions($stripped));
    }

    public function test_webp_loses_what_comes_after_its_declared_size(): void
    {
        $image = imagecreatetruecolor(6, 6);
        $this->assertInstanceOf(GdImage::class, $image);
        ob_start();
        imagewebp($image);
        $simple = (string) ob_get_clean();

        $stripped = $this->stripper->strip($simple.self::SECRET, PostImageType::Webp);

        $this->assertSame($simple, $stripped);
    }

    public function test_webp_shorter_than_it_says_is_refused(): void
    {
        $image = imagecreatetruecolor(6, 6);
        $this->assertInstanceOf(GdImage::class, $image);
        ob_start();
        imagewebp($image);
        $simple = (string) ob_get_clean();

        $this->assertNull($this->stripper->strip(substr($simple, 0, -4), PostImageType::Webp));
    }

    public function test_simple_webp_has_nothing_to_lose(): void
    {
        $image = imagecreatetruecolor(6, 6);
        $this->assertInstanceOf(GdImage::class, $image);
        ob_start();
        imagewebp($image);
        $simple = (string) ob_get_clean();

        $this->assertSame($simple, $this->stripper->strip($simple, PostImageType::Webp));
    }

    public function test_gif_loses_comments_and_foreign_application_blocks_and_keeps_looping(): void
    {
        $image = imagecreate(5, 5);
        $this->assertInstanceOf(GdImage::class, $image);
        imagecolorallocate($image, 0, 0, 0);
        ob_start();
        imagegif($image);
        $plain = (string) ob_get_clean();

        $loop = "\x21\xFF\x0BNETSCAPE2.0\x03\x01\x00\x00\x00";
        $comment = "\x21\xFE".$this->subBlocks(self::SECRET);
        $xmp = "\x21\xFF\x0BXMP DataXMP".$this->subBlocks(self::SECRET);
        $tagged = $this->insertBeforeFirstImage($plain, $loop.$comment.$xmp);

        $stripped = $this->stripper->strip($tagged, PostImageType::Gif);

        $this->assertIsString($stripped);
        $this->assertStringNotContainsString(self::SECRET, $stripped);
        $this->assertStringContainsString('NETSCAPE2.0', $stripped);
        $this->assertSame([5, 5], $this->dimensions($stripped));
    }

    public function test_truncated_files_are_refused(): void
    {
        $jpeg = $this->jpeg(8, 8);

        $this->assertNull($this->stripper->strip(substr($jpeg, 0, 30), PostImageType::Jpeg));
        $this->assertNull($this->stripper->strip('GIF89a', PostImageType::Gif));
        $this->assertNull($this->stripper->strip("\x89PNG\r\n\x1A\n\0\0\0\x0DIHDR", PostImageType::Png));
        $this->assertNull($this->stripper->strip('RIFF', PostImageType::Webp));
        $this->assertNull($this->stripper->strip($jpeg, PostImageType::Png));
    }

    /**
     * @param  int<1, max>  $width
     * @param  int<1, max>  $height
     */
    private function jpeg(int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height);
        $this->assertInstanceOf(GdImage::class, $image);

        return $this->encodeJpeg($image);
    }

    private function encodeJpeg(GdImage $image): string
    {
        ob_start();
        imagejpeg($image, null, 95);

        return (string) ob_get_clean();
    }

    /**
     * @return array{int, int}|null
     */
    private function dimensions(string $bytes): ?array
    {
        $info = getimagesizefromstring($bytes);

        return $info === false ? null : [$info[0], $info[1]];
    }

    /**
     * An Exif APP1 payload with the orientation and a description that gives away a place.
     */
    private function exif(int $orientation): string
    {
        $description = self::SECRET."\0";
        // Header (8) + entry count (2) + two entries (24) + next IFD (4) = 38: where the text goes
        $ifd = pack('n', 2)
            .pack('nnN', 0x0112, 3, 1).pack('n', $orientation)."\0\0"
            .pack('nnNN', 0x010E, 2, strlen($description), 38)
            .pack('N', 0);

        return "Exif\0\0".'MM'.pack('n', 42).pack('N', 8).$ifd.$description;
    }

    /**
     * @param  int<0, 255>  $marker
     */
    private function segment(int $marker, string $payload): string
    {
        return "\xFF".chr($marker).pack('n', strlen($payload) + 2).$payload;
    }

    /**
     * @param  list<string>  $segments
     */
    private function insertAfterSoi(string $jpeg, array $segments): string
    {
        return substr($jpeg, 0, 2).implode('', $segments).substr($jpeg, 2);
    }

    private function chunk(string $type, string $data): string
    {
        return pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));
    }

    /**
     * @param  list<string>  $chunks
     */
    private function insertBeforeIend(string $png, array $chunks): string
    {
        $at = (int) strrpos($png, 'IEND') - 4;

        return substr($png, 0, $at).implode('', $chunks).substr($png, $at);
    }

    private function riffChunk(string $fourCc, string $data): string
    {
        return $fourCc.pack('V', strlen($data)).$data.(strlen($data) % 2 === 1 ? "\0" : '');
    }

    private function uint24(int $value): string
    {
        return substr(pack('V', $value), 0, 3);
    }

    private function subBlocks(string $data): string
    {
        return implode('', array_map(static fn (string $piece): string => chr(min(255, strlen($piece))).$piece, str_split($data, 255)))."\0";
    }

    private function insertBeforeFirstImage(string $gif, string $blocks): string
    {
        $flags = ord($gif[10]);
        $at = 13 + (($flags & 0x80) !== 0 ? 3 * (2 ** (($flags & 0x07) + 1)) : 0);

        return substr($gif, 0, $at).$blocks.substr($gif, $at);
    }
}
