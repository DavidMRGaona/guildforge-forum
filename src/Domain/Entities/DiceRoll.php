<?php

declare(strict_types=1);

namespace Modules\Forum\Domain\Entities;

use DateTimeImmutable;
use Modules\Forum\Domain\Dice\RollResult;
use Modules\Forum\Domain\Exceptions\RollRevealException;
use Modules\Forum\Domain\ValueObjects\PostId;

/**
 * One dice roll of a post: what was asked and what the server threw, or why it threw nothing.
 * Thrown once, when it was published (or when an edit added it): nothing here changes a result.
 * A roll an edit takes out of the post is marked removed and kept.
 *
 * A hidden roll keeps its result secret: only whoever threw it and those the forum's rules let
 * in see it (HiddenRolls). Whoever may reveal it does so once and for good, and who did it and
 * when stays with the roll. Whether a roll is hidden is chosen when it is thrown and never
 * changes afterwards.
 */
final readonly class DiceRoll
{
    /**
     * @param  string  $expression  as the server read it, or as written when it did not read
     * @param  int  $diceCount  dice thrown before explosions
     * @param  string|null  $error  why nothing was thrown; set exactly when there is no result
     * @param  string|null  $rolledBy  the account that threw it (the post's author, or whoever edited it in); null for rolls thrown before it was kept
     * @param  string|null  $revealedBy  the account that revealed a hidden roll; kept when that account is anonymized
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
        public bool $hidden = false,
        public ?string $rolledBy = null,
        public ?DateTimeImmutable $revealedAt = null,
        public ?string $revealedBy = null,
    ) {}

    public static function thrown(string $id, PostId $postId, int $position, string $expression, int $diceCount, RollResult $result, bool $addedOnEdit, DateTimeImmutable $at, bool $hidden = false, ?string $rolledBy = null): self
    {
        return new self($id, $postId, $position, $expression, $diceCount, $result, null, $addedOnEdit, $at, null, $hidden, $rolledBy);
    }

    public static function refused(string $id, PostId $postId, int $position, string $expression, string $error, bool $addedOnEdit, DateTimeImmutable $at, bool $hidden = false, ?string $rolledBy = null): self
    {
        return new self($id, $postId, $position, $expression, 0, null, $error, $addedOnEdit, $at, null, $hidden, $rolledBy);
    }

    /**
     * Rebuilds a stored roll.
     */
    public static function restore(
        string $id,
        PostId $postId,
        int $position,
        string $expression,
        int $diceCount,
        ?RollResult $result,
        ?string $error,
        bool $addedOnEdit,
        DateTimeImmutable $rolledAt,
        ?DateTimeImmutable $removedAt,
        bool $hidden = false,
        ?string $rolledBy = null,
        ?DateTimeImmutable $revealedAt = null,
        ?string $revealedBy = null,
    ): self {
        return new self($id, $postId, $position, $expression, $diceCount, $result, $error, $addedOnEdit, $rolledAt, $removedAt, $hidden, $rolledBy, $revealedAt, $revealedBy);
    }

    public function isRemoved(): bool
    {
        return $this->removedAt instanceof DateTimeImmutable;
    }

    /** Whether its result is still kept from whoever the rules do not let in */
    public function isSecret(): bool
    {
        return $this->hidden && ! $this->revealedAt instanceof DateTimeImmutable;
    }

    public function movedTo(int $position): self
    {
        return $this->with(position: $position);
    }

    public function removedAt(DateTimeImmutable $at): self
    {
        return $this->with(removedAt: $this->removedAt ?? $at);
    }

    /**
     * The roll revealed to everyone from now on. Only a hidden roll still in its post, and only
     * once: there is no way back.
     *
     * @throws RollRevealException
     */
    public function revealedBy(string $accountId, DateTimeImmutable $at): self
    {
        if (! $this->hidden) {
            throw RollRevealException::notHidden($this->id);
        }
        if (! $this->isSecret()) {
            throw RollRevealException::alreadyRevealed($this->id);
        }
        if ($this->isRemoved()) {
            throw RollRevealException::removed($this->id);
        }

        return new self($this->id, $this->postId, $this->position, $this->expression, $this->diceCount, $this->result, $this->error, $this->addedOnEdit, $this->rolledAt, $this->removedAt, $this->hidden, $this->rolledBy, $at, $accountId);
    }

    private function with(?int $position = null, ?DateTimeImmutable $removedAt = null): self
    {
        return new self($this->id, $this->postId, $position ?? $this->position, $this->expression, $this->diceCount, $this->result, $this->error, $this->addedOnEdit, $this->rolledAt, $removedAt ?? $this->removedAt, $this->hidden, $this->rolledBy, $this->revealedAt, $this->revealedBy);
    }
}
