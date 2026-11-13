<?php

declare(strict_types=1);

namespace Modules\Forum\Application\Content\Nodes;

use Modules\Forum\Application\Content\Attributes;
use Modules\Forum\Application\Content\DocumentLimits;
use Modules\Forum\Application\Content\NodeLimit;
use Modules\Forum\Application\Content\Rendering\Html;
use Modules\Forum\Application\Content\Rendering\RenderContext;
use Modules\Forum\Domain\Exceptions\InvalidDocumentException;
use Modules\Forum\Domain\ValueObjects\RemoteUrl;

/**
 * An image in a post. `origin` says where it comes from:
 *
 * - `upload`: a file uploaded to the forum; `src` is its stored path in the forum's own folder.
 * - `external`: an image from another site; `src` is its address there (https, as the server
 *   wrote it) and `path` the copy the forum keeps of it. Readers are only ever shown the copy,
 *   so the other site never learns who reads the post, and the image stays if it goes there.
 *
 * Never a URL to show directly: images from elsewhere could track readers, and only files the
 * forum's media source claims count as used in the media library.
 */
final class ImageNode extends AbstractNodeType
{
    public const string ORIGIN_UPLOAD = 'upload';

    public const string ORIGIN_EXTERNAL = 'external';

    public const array ORIGINS = [self::ORIGIN_UPLOAD, self::ORIGIN_EXTERNAL];

    /** The folder post images are uploaded to, claimed by the forum's media source */
    public const string FOLDER = 'forum/posts/';

    /** The folder of the copies of images from other sites, claimed by the same source */
    public const string EXTERNAL_FOLDER = 'forum/external/';

    public const string PATH_PATTERN = '#^forum/posts/\d{4}/\d{2}/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\.(jpg|png|webp|gif)\z#';

    public const string EXTERNAL_PATH_PATTERN = '#^forum/external/\d{4}/\d{2}/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\.(jpg|png|webp|gif)\z#';

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
        $origin = $attributes['origin'] ?? null;
        Attributes::only($attributes, ['origin', 'src', 'path', 'alt'], $path);

        // The editor writes every attribute of the node: an upload has no copy, so null
        if ($origin !== self::ORIGIN_EXTERNAL && ($attributes['path'] ?? null) !== null) {
            throw InvalidDocumentException::because(InvalidDocumentException::INVALID_ATTRIBUTE, $path, 'Only images from other sites have a copy');
        }

        if (! in_array($origin, self::ORIGINS, true)) {
            throw InvalidDocumentException::because(InvalidDocumentException::INVALID_IMAGE, $path, 'Images say where they come from');
        }

        $src = $attributes['src'] ?? null;
        if ($origin === self::ORIGIN_UPLOAD && (! is_string($src) || preg_match(self::PATH_PATTERN, $src) !== 1)) {
            throw InvalidDocumentException::because(InvalidDocumentException::INVALID_IMAGE, $path, 'Images are uploaded to the forum');
        }

        if ($origin === self::ORIGIN_EXTERNAL) {
            if (! is_string($src) || RemoteUrl::tryFrom($src)?->value !== $src) {
                throw InvalidDocumentException::because(InvalidDocumentException::INVALID_IMAGE, $path, 'Images from other sites keep their https address');
            }
            $copy = $attributes['path'] ?? null;
            if (! is_string($copy) || preg_match(self::EXTERNAL_PATH_PATTERN, $copy) !== 1) {
                throw InvalidDocumentException::because(InvalidDocumentException::INVALID_IMAGE, $path, 'Images from other sites are shown from the forum copy');
            }
        }

        Attributes::optionalText($attributes, 'alt', self::MAX_ALT_LENGTH, $path);
    }

    /**
     * The stored file a node shows (an upload, or the copy of an image from another site), or
     * null when the attributes do not name one. Checked again: a stored document is never
     * trusted to have been validated.
     *
     * @param  array<string, mixed>  $attributes
     */
    public static function storedPath(array $attributes): ?string
    {
        $origin = Attributes::string($attributes, 'origin');
        $path = $origin === self::ORIGIN_EXTERNAL ? Attributes::string($attributes, 'path') : Attributes::string($attributes, 'src');
        $pattern = $origin === self::ORIGIN_EXTERNAL ? self::EXTERNAL_PATH_PATTERN : self::PATH_PATTERN;

        return in_array($origin, self::ORIGINS, true) && $path !== null && preg_match($pattern, $path) === 1 ? $path : null;
    }

    /**
     * The address an image from another site came from, or null for uploads.
     *
     * @param  array<string, mixed>  $attributes
     */
    public static function remoteUrl(array $attributes): ?RemoteUrl
    {
        $src = Attributes::string($attributes, 'src');

        return Attributes::string($attributes, 'origin') === self::ORIGIN_EXTERNAL && $src !== null ? RemoteUrl::tryFrom($src) : null;
    }

    public function toHtml(array $attributes, array $children, RenderContext $context): string
    {
        $stored = self::storedPath($attributes);
        $url = $stored === null ? null : $context->imageUrl($stored);
        $alt = Attributes::string($attributes, 'alt') ?? '';
        $remote = self::remoteUrl($attributes);

        if ($url === null) {
            // A copy that cannot be shown leaves the link: following it is the reader's choice
            return $remote instanceof RemoteUrl
                ? '<p class="forum-image forum-image--unavailable">'.$this->sourceLink($remote, $alt === '' ? $remote->host : $alt).'</p>'
                : '';
        }

        $image = '<img src="'.Html::escape($url).'" alt="'.Html::escape($alt).'" loading="lazy" decoding="async">';
        $caption = $remote instanceof RemoteUrl
            ? '<figcaption class="forum-image__source">'.Html::escape(__('forum::messages.content.image_source')).' '.$this->sourceLink($remote, $remote->host).'</figcaption>'
            : '';

        return '<figure class="forum-image">'.$image.$caption.'</figure>';
    }

    private function sourceLink(RemoteUrl $url, string $text): string
    {
        return '<a href="'.Html::escape($url->value).'" target="_blank" rel="nofollow ugc noopener noreferrer" referrerpolicy="no-referrer">'.Html::escape($text).'</a>';
    }

    public function toText(array $attributes, array $children, RenderContext $context): string
    {
        return Attributes::string($attributes, 'alt') ?? '';
    }
}
