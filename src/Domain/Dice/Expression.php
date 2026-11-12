<?php

declare(strict_types=1);

namespace Modules\Forum\Domain\Dice;

/**
 * A parsed roll: terms joined by `+` and `-`.
 */
final readonly class Expression
{
    /**
     * @param  list<Term>  $terms
     */
    public function __construct(public array $terms) {}

    /**
     * Lowercase, no spaces, one spelling per roll: two texts that mean the same dice have the
     * same canonical form. It tells whether an edited message still holds the same roll.
     */
    public function canonical(): string
    {
        $out = '';
        foreach ($this->terms as $index => $term) {
            $out .= ($term->sign() < 0 ? '-' : ($index > 0 ? '+' : '')).$term->canonical();
        }

        return $out;
    }

    /** Dice thrown, before explosions */
    public function diceCount(): int
    {
        return array_sum(array_map(static fn (Term $term): int => $term->diceCount(), $this->terms));
    }
}
