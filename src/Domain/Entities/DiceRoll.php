<?php

declare(strict_types=1);

namespace Modules\Forum\Domain\Entities;

use DateTimeImmutable;
use Modules\Forum\Domain\Dice\RollResult;
use Modules\Forum\Domain\ValueObjects\PostId;

/**
 * One dice roll of a post: what was asked and what the server threw, or why it threw nothing.
 * Thrown once, when it was published (or when an edit added it): nothing here changes a result.
 * A roll an edit takes out of the post is marked removed and kept.
 */
final readonly class DiceRoll
{
    /**
     * @param  string  $expression  as the server read it, or as written when it did not read
     * @param  int  $diceCount  dice thrown before explosions
     * @param  string|null  $error  why nothing was thrown; set exactly when there is no result
     */
    private function __construct(
        public string $id,
        public PostId $postId,
        public int $position,
        public string $expression,
        public int $diceCount,
        public ?RollResult $result,
        public ?string $error,
        public bool $addedOnEdit,
        public DateTimeImmutable $rolledAt,
        public ?DateTimeImmutable $removedAt = null,
    ) {}

    public static function thrown(string $id, PostId $postId, int $position, string $expression, int $diceCount, RollResult $result, bool $addedOnEdit, DateTimeImmutable $at): self
    {
        return new self($id, $postId, $position, $expression, $diceCount, $result, null, $addedOnEdit, $at);
    }

    public static function refused(string $id, PostId $postId, int $position, string $expression, string $error, bool $addedOnEdit, DateTimeImmutable $at): self
    {
        return new self($id, $postId, $position, $expression, 0, null, $error, $addedOnEdit, $at);
    }

    /**
     * Rebuilds a stored roll.
     */
    public static function restore(string $id, PostId $postId, int $position, string $expression, int $diceCount, ?RollResult $result, ?string $error, bool $addedOnEdit, DateTimeImmutable $rolledAt, ?DateTimeImmutable $removedAt): self
    {
        return new self($id, $postId, $position, $expression, $diceCount, $result, $error, $addedOnEdit, $rolledAt, $removedAt);
    }

    public function isRemoved(): bool
    {
        return $this->removedAt instanceof DateTimeImmutable;
    }

    public function movedTo(int $position): self
    {
        return new self($this->id, $this->postId, $position, $this->expression, $this->diceCount, $this->result, $this->error, $this->addedOnEdit, $this->rolledAt, $this->removedAt);
    }

    public function removedAt(DateTimeImmutable $at): self
    {
        return new self($this->id, $this->postId, $this->position, $this->expression, $this->diceCount, $this->result, $this->error, $this->addedOnEdit, $this->rolledAt, $this->removedAt ?? $at);
    }
}
