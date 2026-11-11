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
 * An image in a post. `origin` says where it comes from; for now only `upload`: a file uploaded
 * to the forum, whose `src` is its stored path in the forum's own folder, never a URL (images
 * from elsewhere could track readers, and only files the forum's media source claims count as
 * used in the media library). Other origins can be added later without touching stored posts.
 */
final class ImageNode extends AbstractNodeType
{
    public const string ORIGIN_UPLOAD = 'upload';

    public const array ORIGINS = [self::ORIGIN_UPLOAD];

    /** The folder post images are uploaded to, claimed by the forum's media source */
    public const string FOLDER = 'forum/posts/';

    public const string PATH_PATTERN = '#^forum/posts/\d{4}/\d{2}/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\.(jpg|png|webp|gif)$#';

    public const int MAX_ALT_LENGTH = 300;

    /**
     * @param  int  $maxPerDocument  images one post may hold (the forum settings)
     */
    public function __construct(
        private readonly int $maxPerDocument = DocumentLimits::MAX_IMAGES,
    ) {}

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
        return new NodeLimit($this->maxPerDocument, InvalidDocumentException::TOO_MANY_IMAGES);
    }

    public function validateAttributes(array $attributes, string $path): void
    {
        Attributes::only($attributes, ['origin', 'src', 'alt'], $path);

        if (! in_array($attributes['origin'] ?? null, self::ORIGINS, true)) {
            throw InvalidDocumentException::because(InvalidDocumentException::INVALID_IMAGE, $path, 'Images say where they come from');
        }

        $src = $attributes['src'] ?? null;
        if (! is_string($src) || preg_match(self::PATH_PATTERN, $src) !== 1) {
            throw InvalidDocumentException::because(InvalidDocumentException::INVALID_IMAGE, $path, 'Images are uploaded to the forum');
        }

        Attributes::optionalText($attributes, 'alt', self::MAX_ALT_LENGTH, $path);
    }

    public function toHtml(array $attributes, array $children, RenderContext $context): string
    {
        // Checked again: a stored document is never trusted to have been validated
        $src = Attributes::string($attributes, 'src') ?? '';
        $url = Attributes::string($attributes, 'origin') === self::ORIGIN_UPLOAD && preg_match(self::PATH_PATTERN, $src) === 1
            ? $context->imageUrl($src)
            : null;

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
