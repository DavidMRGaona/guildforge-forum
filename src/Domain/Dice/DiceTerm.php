<?php

declare(strict_types=1);

namespace Modules\Forum\Domain\Dice;

/**
 * `NdS` and its modifiers. A Fate die (`dF`) has the faces -1, 0 and 1.
 */
final readonly class DiceTerm implements Term
{
    public const string KEEP_HIGHEST = 'kh';

    public const string KEEP_LOWEST = 'kl';

    public const string DROP_HIGHEST = 'dh';

    public const string DROP_LOWEST = 'dl';

    /**
     * @param  1|-1  $sign
     * @param  string|null  $keepRule  one of the KEEP_* and DROP_* constants
     */
    public function __construct(
        private int $sign,
        public int $count,
        public int $sides,
        public bool $fate = false,
        public ?Comparison $explode = null,
        public ?string $keepRule = null,
        public int $keepCount = 0,
        public ?Comparison $success = null,
        public ?Comparison $failure = null,
    ) {}

    public function sign(): int
    {
        return $this->sign;
    }

    public function minFace(): int
    {
        return $this->fate ? -1 : 1;
    }

    public function maxFace(): int
    {
        return $this->fate ? 1 : $this->sides;
    }

    public function diceCount(): int
    {
        return $this->count;
    }

    public function canonical(): string
    {
        $out = $this->count.'d'.($this->fate ? 'f' : $this->sides);

        if ($this->explode instanceof Comparison) {
            $out .= '!'.$this->explode->canonical();
        }

        if ($this->keepRule !== null) {
            $out .= $this->keepRule.$this->keepCount;
        }

        if ($this->success instanceof Comparison) {
            $out .= $this->success->canonical();
        }

        if ($this->failure instanceof Comparison) {
            $out .= 'f'.$this->failure->canonical();
        }

        return $out;
    }
}
