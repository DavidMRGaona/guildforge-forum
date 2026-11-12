<?php

declare(strict_types=1);

namespace Modules\Forum\Application\Embeds;

use InvalidArgumentException;

/**
 * How to load a service's player: its https address, the sandbox tokens it cannot work
 * without, its shape and the referrer policy of the frame.
 */
final readonly class EmbedPlayer
{
    public const string VIDEO = 'video';

    public const string PORTRAIT = 'portrait';

    public const string POST = 'post';

    public const string NO_REFERRER = 'no-referrer';

    /** Only the site's origin, never the page: for services whose player refuses to load without one */
    public const string ORIGIN_ONLY = 'strict-origin';

    /** Runs the player's own script, in its own origin (it reads its storage and calls its API) */
    public const string SCRIPTS = 'allow-scripts';

    public const string SAME_ORIGIN = 'allow-same-origin';

    /** Links of the post or the player («Ver en…») open in a new tab, as a normal page */
    public const string POPUPS = 'allow-popups';

    public const string POPUPS_ESCAPE = 'allow-popups-to-escape-sandbox';

    /** Sending the video to a screen (Presentation API) */
    public const string PRESENTATION = 'allow-presentation';

    /**
     * The only tokens a player may get. Never top navigation, forms, modals, downloads or
     * pointer lock: a frame can never take the reader away from the forum or ask for anything.
     */
    public const array ALLOWED_SANDBOX = [self::SCRIPTS, self::SAME_ORIGIN, self::POPUPS, self::POPUPS_ESCAPE, self::PRESENTATION];

    /** What a post of a social network needs: its script, its origin and its links */
    public const array POST_SANDBOX = [self::SCRIPTS, self::SAME_ORIGIN, self::POPUPS, self::POPUPS_ESCAPE];

    /** @var list<string> */
    public array $sandbox;

    /**
     * @param  list<string>  $sandbox
     */
    public function __construct(
        public string $src,
        array $sandbox,
        public string $aspect = self::VIDEO,
        public string $referrerPolicy = self::NO_REFERRER,
    ) {
        foreach ($sandbox as $token) {
            if (! in_array($token, self::ALLOWED_SANDBOX, true)) {
                throw new InvalidArgumentException("A player cannot get the sandbox token «{$token}».");
            }
        }

        $this->sandbox = array_values(array_unique($sandbox));
    }

    /** The tokens as the iframe's sandbox attribute */
    public function sandboxAttribute(): string
    {
        return implode(' ', $this->sandbox);
    }
}
