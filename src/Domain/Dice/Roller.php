<?php

declare(strict_types=1);

namespace Modules\Forum\Domain\Dice;

/**
 * Throws the dice of an expression; pure apart from its number generator.
 *
 * Order within a term: throw the dice, add the ones that explode, keep or drop, then sum (or
 * count the successes and subtract the failures).
 */
final readonly class Roller
{
    public function __construct(private NumberGenerator $numbers) {}

    public function roll(Expression $expression): RollResult
    {
        $terms = [];
        $total = 0;

        foreach ($expression->terms as $term) {
            if ($term instanceof ConstantTerm) {
                $terms[] = ['sign' => $term->sign(), 'kind' => 'const', 'value' => $term->value];
                $total += $term->sign() * $term->value;

                continue;
            }

            if (! $term instanceof DiceTerm) {
                continue;
            }

            [$dice, $subtotal, $mode] = $this->rollTerm($term);
            $terms[] = [
                'sign' => $term->sign(),
                'kind' => 'dice',
                'spec' => $term->canonical(),
                'mode' => $mode,
                'dice' => $dice,
                'subtotal' => $subtotal,
            ];
            $total += $term->sign() * $subtotal;
        }

        return new RollResult($terms, $total);
    }

    /**
     * @return array{list<array{v: int, k: bool, x: bool}>, int, string}
     */
    private function rollTerm(DiceTerm $term): array
    {
        /** @var list<array{v: int, k: bool, x: bool}> $dice */
        $dice = [];

        for ($i = 0; $i < $term->count; $i++) {
            $dice[] = ['v' => $this->numbers->between($term->minFace(), $term->maxFace()), 'k' => true, 'x' => false];
        }

        if ($term->explode instanceof Comparison) {
            // The list grows while it is walked: an extra die can explode too
            for ($i = 0, $extra = 0; $i < count($dice) && $extra < Limits::MAX_EXPLOSION_EXTRA; $i++) {
                if ($term->explode->matches($dice[$i]['v'])) {
                    $dice[] = ['v' => $this->numbers->between($term->minFace(), $term->maxFace()), 'k' => true, 'x' => true];
                    $extra++;
                }
            }
        }

        if ($term->keepRule !== null) {
            $dice = $this->applyKeep($dice, $term);
        }

        $kept = array_filter($dice, static fn (array $die): bool => $die['k']);

        if ($term->success instanceof Comparison) {
            $success = $term->success;
            $failure = $term->failure;
            $successes = count(array_filter($kept, static fn (array $die): bool => $success->matches($die['v'])));
            $failures = $failure instanceof Comparison
                ? count(array_filter($kept, static fn (array $die): bool => $failure->matches($die['v'])))
                : 0;

            return [$dice, $successes - $failures, 'successes'];
        }

        return [$dice, array_sum(array_column($kept, 'v')), 'sum'];
    }

    /**
     * Marks the dice that do not count. A tie keeps the earlier die, so the result never
     * depends on how the sort treats equals.
     *
     * @param  list<array{v: int, k: bool, x: bool}>  $dice
     * @return list<array{v: int, k: bool, x: bool}>
     */
    private function applyKeep(array $dice, DiceTerm $term): array
    {
        $order = array_keys($dice);
        $highestFirst = in_array($term->keepRule, [DiceTerm::KEEP_HIGHEST, DiceTerm::DROP_LOWEST], true);

        usort($order, static function (int $a, int $b) use ($dice, $highestFirst): int {
            $byValue = $highestFirst ? $dice[$b]['v'] <=> $dice[$a]['v'] : $dice[$a]['v'] <=> $dice[$b]['v'];

            return $byValue !== 0 ? $byValue : $a <=> $b;
        });

        $keeps = in_array($term->keepRule, [DiceTerm::KEEP_HIGHEST, DiceTerm::KEEP_LOWEST], true);
        // The dice that explosions added are in the pool: «kh3» keeps three of all of them
        $kept = $keeps ? $term->keepCount : max(1, count($dice) - $term->keepCount);

        foreach ($order as $rank => $index) {
            $dice[$index]['k'] = $rank < $kept;
        }

        return $dice;
    }
}
