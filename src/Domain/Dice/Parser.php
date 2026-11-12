<?php

declare(strict_types=1);

namespace Modules\Forum\Domain\Dice;

/**
 * Reads the expression of a roll (the reason is kept apart, by the node):
 *
 *     2d6+3   1d20-1   3d6+1d4   dice and constants added or subtracted; d% is d100
 *     4dF                        Fate dice (-1, 0, +1)
 *     5d10>=7                    pool: how many dice reach 7 (>=, >, <=, <, =)
 *     5d10>=8f=1                 …minus the dice that show 1
 *     4d6kh3  4d6dl1  2d20kl1    keep or drop the highest or lowest (kh, kl, dh, dl; k = kh)
 *     3d6!  3d10!>=9             a die that shows its maximum (or matches) is thrown again
 *
 * Case does not matter, and spaces are allowed around `+` and `-`. Anything else is refused.
 */
final class Parser
{
    private string $text = '';

    private int $pos = 0;

    /**
     * @throws InvalidNotation
     */
    public function parse(string $input): Expression
    {
        if (strlen($input) > Limits::MAX_EXPRESSION_LENGTH) {
            throw new InvalidNotation(InvalidNotation::TOO_LONG);
        }

        $this->text = strtolower(trim($input));
        $this->pos = 0;

        if ($this->text === '') {
            throw new InvalidNotation(InvalidNotation::EMPTY);
        }

        $terms = [$this->term(1) ?? throw new InvalidNotation(InvalidNotation::SYNTAX)];

        while (true) {
            $this->skipSpaces();
            $sign = $this->peek();

            if ($sign !== '+' && $sign !== '-') {
                break;
            }

            $this->pos++;
            $this->skipSpaces();
            $terms[] = $this->term($sign === '-' ? -1 : 1) ?? throw new InvalidNotation(InvalidNotation::SYNTAX);

            if (count($terms) > Limits::MAX_TERMS) {
                throw new InvalidNotation(InvalidNotation::TOO_MANY_TERMS);
            }
        }

        // «2d6 fuerza» or «2d6)»: whatever is left is not part of a roll
        if ($this->peek() !== '') {
            throw new InvalidNotation(InvalidNotation::SYNTAX);
        }

        return new Expression($terms);
    }

    /**
     * @param  1|-1  $sign
     */
    private function term(int $sign): ?Term
    {
        $start = $this->pos;
        $number = $this->number();

        if ($this->peek() !== 'd') {
            if ($number === null) {
                $this->pos = $start;

                return null;
            }

            if ($number > Limits::MAX_CONSTANT) {
                throw new InvalidNotation(InvalidNotation::NUMBER_TOO_BIG);
            }

            return new ConstantTerm($sign, $number);
        }

        $this->pos++;
        $fate = false;

        if ($this->peek() === 'f') {
            $fate = true;
            $sides = 3;
            $this->pos++;
        } elseif ($this->peek() === '%') {
            $sides = 100;
            $this->pos++;
        } else {
            $sides = $this->number();

            if ($sides === null) {
                $this->pos = $start;

                return null;
            }
        }

        $count = $number ?? 1;

        if ($count < 1 || (! $fate && $sides < 2)) {
            throw new InvalidNotation(InvalidNotation::BAD_DICE);
        }

        if ($count > Limits::MAX_DICE_PER_TERM) {
            throw new InvalidNotation(InvalidNotation::TOO_MANY_DICE);
        }

        if ($sides > Limits::MAX_SIDES) {
            throw new InvalidNotation(InvalidNotation::TOO_MANY_SIDES);
        }

        return $this->modifiers($sign, $count, $sides, $fate);
    }

    /**
     * @param  1|-1  $sign
     */
    private function modifiers(int $sign, int $count, int $sides, bool $fate): DiceTerm
    {
        $explode = null;
        $keepRule = null;
        $success = null;
        $failure = null;
        $keepCount = 0;

        while (true) {
            $char = $this->peek();

            if ($char === '!') {
                if ($explode instanceof Comparison || $fate) {
                    throw new InvalidNotation(InvalidNotation::BAD_EXPLOSION);
                }

                $this->pos++;
                $explode = $this->comparison() ?? new Comparison('=', $sides);
            } elseif ($char === 'k' || ($char === 'd' && in_array($this->peek(1), ['h', 'l'], true))) {
                if ($keepRule !== null) {
                    throw new InvalidNotation(InvalidNotation::BAD_KEEP);
                }

                $this->pos++;
                $direction = $this->peek();
                $keepRule = $char === 'k' ? DiceTerm::KEEP_HIGHEST : null;

                if ($direction === 'h' || $direction === 'l') {
                    $this->pos++;
                    $keepRule = $char.$direction;
                }

                $keepCount = $this->number() ?? 1;
                $keepsAtMost = in_array($keepRule, [DiceTerm::DROP_HIGHEST, DiceTerm::DROP_LOWEST], true) ? $count - 1 : $count;

                if ($keepRule === null || $keepCount < 1 || $keepCount > $keepsAtMost) {
                    throw new InvalidNotation(InvalidNotation::BAD_KEEP);
                }
            } elseif ($char === 'f' && ! $fate) {
                $this->pos++;
                $failure = $this->comparison();

                if (! $failure instanceof Comparison || ! $success instanceof Comparison) {
                    throw new InvalidNotation(InvalidNotation::BAD_MODIFIER);
                }
            } elseif (in_array($char, ['>', '<', '='], true)) {
                if ($success instanceof Comparison) {
                    throw new InvalidNotation(InvalidNotation::BAD_MODIFIER);
                }

                $success = $this->comparison();
            } else {
                break;
            }
        }

        $term = new DiceTerm($sign, $count, $sides, $fate, $explode, $keepRule, $keepCount, $success, $failure);

        if ($explode instanceof Comparison) {
            $matching = 0;

            for ($face = $term->minFace(); $face <= $term->maxFace(); $face++) {
                $matching += $explode->matches($face) ? 1 : 0;
            }

            // Never or always: a die that explodes on every face would only stop at the cap
            if ($matching === 0 || $matching === $sides) {
                throw new InvalidNotation(InvalidNotation::BAD_EXPLOSION);
            }
        }

        return $term;
    }

    private function comparison(): ?Comparison
    {
        foreach (['>=', '<=', '==', '>', '<', '='] as $operator) {
            if (substr($this->text, $this->pos, strlen($operator)) !== $operator) {
                continue;
            }

            $this->pos += strlen($operator);
            $negative = $this->peek() === '-';
            if ($negative) {
                $this->pos++;
            }
            $value = $this->number() ?? throw new InvalidNotation(InvalidNotation::BAD_MODIFIER);

            if ($value > Limits::MAX_CONSTANT) {
                throw new InvalidNotation(InvalidNotation::NUMBER_TOO_BIG);
            }

            return new Comparison($operator === '==' ? '=' : $operator, $negative ? -$value : $value);
        }

        return null;
    }

    private function number(): ?int
    {
        $start = $this->pos;

        while ($this->peek() !== '' && ctype_digit($this->peek())) {
            $this->pos++;
        }

        if ($this->pos === $start) {
            return null;
        }

        if ($this->pos - $start > 7) {
            throw new InvalidNotation(InvalidNotation::NUMBER_TOO_BIG);
        }

        return (int) substr($this->text, $start, $this->pos - $start);
    }

    private function peek(int $offset = 0): string
    {
        return $this->text[$this->pos + $offset] ?? '';
    }

    private function skipSpaces(): void
    {
        while ($this->peek() !== '' && ctype_space($this->peek())) {
            $this->pos++;
        }
    }
}
