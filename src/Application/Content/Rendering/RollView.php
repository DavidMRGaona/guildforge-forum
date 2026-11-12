<?php

declare(strict_types=1);

namespace Modules\Forum\Application\Content\Rendering;

use Modules\Forum\Domain\Dice\RollResult;
use Modules\Forum\Domain\Entities\DiceRoll;

/**
 * A stored roll as a reader sees it: what it threw, or why it was not thrown.
 */
final readonly class RollView
{
    /**
     * @param  string|null  $error  the reason code of a roll that was not thrown
     */
    public function __construct(
        public string $id,
        public string $expression,
        public ?RollResult $result,
        public ?string $error,
        public bool $addedOnEdit,
    ) {}

    public static function of(DiceRoll $roll): self
    {
        return new self($roll->id, $roll->expression, $roll->result, $roll->error, $roll->addedOnEdit);
    }
}
