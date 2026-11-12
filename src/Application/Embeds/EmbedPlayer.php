<?php

declare(strict_types=1);

namespace Modules\Forum\Application\Embeds;

/**
 * How to load a service's player: its https address, its shape and the referrer policy of the
 * frame.
 */
final readonly class EmbedPlayer
{
    public const string VIDEO = 'video';

    public const string PORTRAIT = 'portrait';

    public const string POST = 'post';

    public const string NO_REFERRER = 'no-referrer';

    /** Only the site's origin, never the page: for services whose player refuses to load without one */
    public const string ORIGIN_ONLY = 'strict-origin';

    public function __construct(
        public string $src,
        public string $aspect = self::VIDEO,
        public string $referrerPolicy = self::NO_REFERRER,
    ) {}
}
