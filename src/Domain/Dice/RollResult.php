<?php

declare(strict_types=1);

namespace Modules\Forum\Domain\Dice;

/**
 * What a roll threw: every term with its dice, and the total.
 *
 * A term is `{sign, kind: const, value}` or `{sign, kind: dice, spec, mode: sum|successes,
 * dice, subtotal}`; a die is `{v: value, k: counts (false when dropped), x: came from an
 * explosion}`.
 */
final readonly class RollResult
{
    /**
     * @param  list<array<string, mixed>>  $terms
     */
    public function __construct(
        public array $terms,
        public int $total,
    ) {}

    /**
     * @return array{terms: list<array<string, mixed>>, total: int}
     */
    public function toArray(): array
    {
        return ['terms' => $this->terms, 'total' => $this->total];
    }

    /**
     * @return self|null null when the stored value is not a roll result
     */
    public static function fromArray(mixed $data): ?self
    {
        if (! is_array($data) || ! isset($data['terms'], $data['total']) || ! is_array($data['terms']) || ! is_int($data['total'])) {
            return null;
        }

        return new self(array_values($data['terms']), $data['total']);
    }
}
