<?php

declare(strict_types=1);

namespace Modules\Forum\Domain\Dice;

use InvalidArgumentException;

/**
 * A roll that is not a valid expression or goes over a limit. The reason is a stable code the
 * interface translates (`forum::messages.dice.errors.<reason>`).
 */
final class InvalidNotation extends InvalidArgumentException
{
    public const string EMPTY = 'empty';

    public const string SYNTAX = 'syntax';

    public const string TOO_LONG = 'too_long';

    public const string BAD_DICE = 'bad_dice';

    public const string TOO_MANY_DICE = 'too_many_dice';

    public const string TOO_MANY_SIDES = 'too_many_sides';

    public const string TOO_MANY_TERMS = 'too_many_terms';

    public const string NUMBER_TOO_BIG = 'number_too_big';

    public const string BAD_KEEP = 'bad_keep';

    public const string BAD_EXPLOSION = 'bad_explosion';

    public const string BAD_MODIFIER = 'bad_modifier';

    public const string TOO_MANY_DICE_IN_POST = 'too_many_dice_in_post';

    public function __construct(public readonly string $reason)
    {
        parent::__construct($reason);
    }
}
