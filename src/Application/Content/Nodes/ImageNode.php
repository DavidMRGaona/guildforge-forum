<?php

declare(strict_types=1);

namespace Modules\Forum\Application\Content\Nodes;

use Modules\Forum\Application\Content\Attributes;
use Modules\Forum\Application\Content\DocumentLimits;
use Modules\Forum\Application\Content\NodeLimit;
use Modules\Forum\Application\Content\Rendering\Html;
use Modules\Forum\Application\Content\Rendering\RenderContext;
use Modules\Forum\Domain\Exceptions\InvalidDocumentException;

/**
 * An image uploaded to the forum. It holds the stored path in the forum's own folder, never a
 * URL: images from elsewhere could track readers, and only files the forum's media source
 * claims count as used in the media library.
 */
final class ImageNode extends AbstractNodeType
{
    /** The folder post images are uploaded to, claimed by the forum's media source */
    public const string FOLDER = 'forum/posts/';

    public const string PATH_PATTERN = '#^forum/posts/\d{4}/\d{2}/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\.(jpg|png|webp|gif)$#';

    public const int MAX_ALT_LENGTH = 300;

    public function name(): string
    {
        return 'image';
    }

    public function isSubstantial(): bool
    {
        return true;
    }

    public function limit(): NodeLimit
    {
        return new NodeLimit(DocumentLimits::MAX_IMAGES, InvalidDocumentException::TOO_MANY_IMAGES);
    }

    public function validateAttributes(array $attributes, string $path): void
    {
        Attributes::only($attributes, ['src', 'alt'], $path);

        $src = $attributes['src'] ?? null;
        if (! is_string($src) || preg_match(self::PATH_PATTERN, $src) !== 1) {
            throw InvalidDocumentException::because(InvalidDocumentException::INVALID_IMAGE, $path, 'Images are uploaded to the forum');
        }

        Attributes::optionalText($attributes, 'alt', self::MAX_ALT_LENGTH, $path);
    }

    public function toHtml(array $attributes, array $children, RenderContext $context): string
    {
        $url = $context->imageUrl(Attributes::string($attributes, 'src') ?? '');

        if ($url === null) {
            return '';
        }

        return '<figure class="forum-image"><img src="'.Html::escape($url).'" alt="'.Html::escape(Attributes::string($attributes, 'alt') ?? '').'" loading="lazy" decoding="async"></figure>';
    }

    public function toText(array $attributes, array $children, RenderContext $context): string
    {
        return Attributes::string($attributes, 'alt') ?? '';
    }
}
