<?php

declare(strict_types=1);

namespace Modules\Forum\Application\Content\Marks;

use Modules\Forum\Application\Content\Attributes;
use Modules\Forum\Application\Content\Contracts\MarkTypeInterface;
use Modules\Forum\Application\Content\Rendering\Html;
use Modules\Forum\Domain\Exceptions\InvalidDocumentException;

/**
 * A link: only `href`, and only to http, https or mailto. It opens apart and passes nothing
 * on to the site it points at; search engines are told the forum does not vouch for it.
 */
final class LinkMark implements MarkTypeInterface
{
    public const int MAX_LENGTH = 2048;

    private const array SCHEMES = ['http', 'https', 'mailto'];

    public function name(): string
    {
        return 'link';
    }

    public function validateAttributes(array $attributes, string $path): void
    {
        Attributes::only($attributes, ['href'], $path);

        $href = $attributes['href'] ?? null;
        if (! is_string($href) || ! self::isAllowed($href)) {
            throw InvalidDocumentException::because(InvalidDocumentException::INVALID_LINK, $path, 'Links go to http, https or mailto addresses');
        }
    }

    public function wrapHtml(array $attributes, string $html): string
    {
        $href = Attributes::string($attributes, 'href');

        // Checked again: a stored document is never trusted to have been validated
        if ($href === null || ! self::isAllowed($href)) {
            return $html;
        }

        return '<a href="'.Html::escape($href).'" target="_blank" rel="nofollow ugc noopener noreferrer">'.$html.'</a>';
    }

    /**
     * An absolute http or https URL with a host and without credentials, or a mailto address.
     * Nothing is trimmed or decoded: an address with spaces or control characters is refused.
     */
    public static function isAllowed(string $href): bool
    {
        if ($href === '' || strlen($href) > self::MAX_LENGTH || preg_match('/[\s\x00-\x1F\x7F]/u', $href) === 1) {
            return false;
        }

        $parts = parse_url($href);
        if ($parts === false || ! isset($parts['scheme'])) {
            return false;
        }

        $scheme = strtolower($parts['scheme']);
        if (! in_array($scheme, self::SCHEMES, true)) {
            return false;
        }

        if ($scheme === 'mailto') {
            return isset($parts['path']) && str_contains($parts['path'], '@') && ! isset($parts['host']);
        }

        return isset($parts['host']) && $parts['host'] !== ''
            && ! isset($parts['user']) && ! isset($parts['pass'])
            && str_starts_with(strtolower($href), $scheme.'://');
    }
}
