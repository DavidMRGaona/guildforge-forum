<?php

declare(strict_types=1);

namespace Modules\Forum\Domain\Dice;

/**
 * What a roll may cost. Constants on purpose: no setting a request could reach changes them.
 */
final class Limits
{
    /** Dice in one term (`NdS`), before explosions */
    public const int MAX_DICE_PER_TERM = 100;

    public const int MAX_SIDES = 1000;

    /** Terms (groups of dice and constants) in one expression */
    public const int MAX_TERMS = 10;

    /** Dice that explosions add to one term */
    public const int MAX_EXPLOSION_EXTRA = 100;

    /** The largest constant, and the largest number in a comparison */
    public const int MAX_CONSTANT = 10_000;

    /** Rolls in one message */
    public const int MAX_ROLLS_PER_POST = 10;

    /** Dice (before explosions) the rolls of one message add up to */
    public const int MAX_DICE_PER_POST = 300;

    /** The longest expression that is read, in characters */
    public const int MAX_EXPRESSION_LENGTH = 200;

    /** The longest reason, in characters */
    public const int MAX_REASON_LENGTH = 100;
}
