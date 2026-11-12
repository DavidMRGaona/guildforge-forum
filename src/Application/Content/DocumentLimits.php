<?php

declare(strict_types=1);

namespace Modules\Forum\Application\Content;

use Modules\Forum\Domain\Dice\Limits;

/**
 * How big a post document may be. Generous for long game posts, tight enough that a forged
 * document cannot make the server walk or render something huge.
 */
final class DocumentLimits
{
    /** The document encoded as JSON, in bytes */
    public const int MAX_BYTES = 262_144;

    /** Levels from the document down to its deepest node, both included */
    public const int MAX_DEPTH = 16;

    /** Every node and text run of the document */
    public const int MAX_NODES = 10_000;

    /** Images per post unless the forum settings say otherwise */
    public const int MAX_IMAGES = 10;

    public const int MAX_MENTIONS = 20;

    public const int MAX_QUOTES = 10;

    /** Dice rolls per post (the rest of their limits live in Domain\Dice\Limits) */
    public const int MAX_ROLLS = Limits::MAX_ROLLS_PER_POST;
}
