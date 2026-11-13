<?php

declare(strict_types=1);

namespace Modules\Forum\Application\Content\Rendering;

use Modules\Forum\Domain\Dice\RollResult;
use Modules\Forum\Domain\Entities\DiceRoll;

/**
 * A stored roll as a reader sees it: what it threw, or why it was not thrown. A hidden roll
 * still secret is withheld from whoever the rules keep out: no result, no error, nothing but
 * its expression and reason (the document's). of() gives that view to anyone, so whatever does
 * not know its reader (stored plain text, the search index, mail) never holds a secret result;
 * only HiddenRolls hands out seenBy() to those it lets in.
 */
final readonly class RollView
{
    /**
     * @param  string|null  $error  the reason code of a roll that was not thrown
     * @param  bool  $hidden  thrown as a hidden roll
     * @param  bool  $withheld  its result is kept from this reader
     * @param  string|null  $revealedBy  who revealed it, as the reader sees their name
     * @param  string|null  $revealedAt  when, as the reader reads it
     * @param  bool  $canReveal  the reader may reveal it now
     * @param  bool  $inCampaignSpace  its post is in a campaign's space, whose direction sees it too
     */
    public function __construct(
        public string $id,
        public string $expression,
        public ?RollResult $result,
        public ?string $error,
        public bool $addedOnEdit,
        public bool $hidden = false,
        public bool $withheld = false,
        public bool $revealed = false,
        public ?string $revealedBy = null,
        public ?string $revealedAt = null,
        public bool $canReveal = false,
        public bool $inCampaignSpace = false,
    ) {}

    /**
     * The roll as anyone sees it: a secret one withheld.
     */
    public static function of(DiceRoll $roll): self
    {
        if ($roll->isSecret()) {
            return new self($roll->id, $roll->expression, null, null, $roll->addedOnEdit, hidden: true, withheld: true);
        }

        return new self($roll->id, $roll->expression, $roll->result, $roll->error, $roll->addedOnEdit, hidden: $roll->hidden, revealed: $roll->hidden);
    }

    /**
     * The roll for a reader the rules let see it, secret or not.
     */
    public static function seenBy(DiceRoll $roll, bool $canReveal, bool $inCampaignSpace): self
    {
        return new self(
            $roll->id,
            $roll->expression,
            $roll->result,
            $roll->error,
            $roll->addedOnEdit,
            hidden: $roll->hidden,
            revealed: $roll->hidden && ! $roll->isSecret(),
            canReveal: $canReveal && $roll->isSecret(),
            inCampaignSpace: $inCampaignSpace,
        );
    }

    /**
     * The same view, saying who revealed it and when.
     */
    public function withRevealer(?string $name, ?string $at): self
    {
        return new self($this->id, $this->expression, $this->result, $this->error, $this->addedOnEdit, $this->hidden, $this->withheld, $this->revealed, $name, $at, $this->canReveal, $this->inCampaignSpace);
    }

    /** Whether the reader sees the result of a roll the rest of the readers do not */
    public function isSecretForOthers(): bool
    {
        return $this->hidden && ! $this->withheld && ! $this->revealed;
    }
}
