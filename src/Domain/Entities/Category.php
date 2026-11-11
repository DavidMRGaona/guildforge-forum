<?php

declare(strict_types=1);

namespace Modules\Forum\Domain\Entities;

use Modules\Forum\Domain\Enums\ReadOnlyOrigin;
use Modules\Forum\Domain\Enums\RosterScope;
use Modules\Forum\Domain\Enums\SystemCategory;
use Modules\Forum\Domain\Exceptions\InvalidCategoryException;
use Modules\Forum\Domain\ValueObjects\CategoryAppearance;
use Modules\Forum\Domain\ValueObjects\CategoryAudience;
use Modules\Forum\Domain\ValueObjects\CategoryId;
use Modules\Forum\Domain\ValueObjects\CategoryModerators;
use Modules\Forum\Domain\ValueObjects\CategorySlug;
use Modules\Forum\Domain\ValueObjects\CategorySubject;

/**
 * A section of the forum, at the top or under one top-level category (never deeper; the tree
 * is checked by CategoryManager, which can see the other categories). It decides who reads it
 * and who writes in it, and who moderates it besides the forum's moderators; the decision
 * itself is CategoryAccessPolicy's. Its counters are not part of it: the persistence layer keeps
 * them up to date with every write.
 */
final class Category
{
    public const int MAX_NAME_LENGTH = 80;

    public const int MAX_DESCRIPTION_LENGTH = 300;

    private string $name;

    private ?string $description;

    private int $position;

    private ?CategoryId $parentId;

    private CategoryAudience $viewAudience;

    private CategoryAudience $writeAudience;

    private CategoryModerators $moderators;

    /**
     * By default a top-level category anyone reads and any account writes in.
     *
     * @throws InvalidCategoryException
     */
    public function __construct(
        private readonly CategoryId $id,
        string $name,
        private CategorySlug $slug,
        ?string $description = null,
        int $position = 0,
        ?CategoryId $parentId = null,
        private CategoryAppearance $appearance = new CategoryAppearance(null, null),
        ?CategoryAudience $viewAudience = null,
        ?CategoryAudience $writeAudience = null,
        ?CategoryModerators $moderators = null,
        private ?CategorySubject $subject = null,
        private readonly ?SystemCategory $systemKey = null,
        private ?ReadOnlyOrigin $readOnlyBy = null,
    ) {
        $this->name = $this->checkedName($name);
        $this->description = $this->checkedDescription($description);
        $this->position = $this->checkedPosition($position);
        $this->moveUnder($parentId);
        $this->changeAccess(
            $viewAudience ?? CategoryAudience::everyone(),
            $writeAudience ?? CategoryAudience::signedIn(),
            $moderators ?? CategoryModerators::none(),
        );
    }

    /**
     * One of the categories the forum relies on, with its stable key.
     *
     * @throws InvalidCategoryException
     */
    public static function system(CategoryId $id, SystemCategory $key, string $name, CategorySlug $slug, int $position): self
    {
        return new self($id, $name, $slug, null, $position, systemKey: $key);
    }

    /**
     * @throws InvalidCategoryException
     */
    public function rename(string $name, CategorySlug $slug, ?string $description): void
    {
        $this->name = $this->checkedName($name);
        $this->slug = $slug;
        $this->description = $this->checkedDescription($description);
    }

    /**
     * @throws InvalidCategoryException
     */
    public function moveToPosition(int $position): void
    {
        $this->position = $this->checkedPosition($position);
    }

    /**
     * Under a top-level category, or back to the top with null.
     *
     * @throws InvalidCategoryException when it would be its own parent
     */
    public function moveUnder(?CategoryId $parentId): void
    {
        if ($parentId?->equals($this->id) === true) {
            throw InvalidCategoryException::ownParent($this->id->value);
        }

        $this->parentId = $parentId;
    }

    public function restyle(CategoryAppearance $appearance): void
    {
        $this->appearance = $appearance;
    }

    /**
     * @throws InvalidCategoryException when guests could write, or a roster rule has no publication
     */
    public function changeAccess(CategoryAudience $view, CategoryAudience $write, CategoryModerators $moderators): void
    {
        if ($write->allowsGuests()) {
            throw InvalidCategoryException::guestsCannotWrite();
        }

        if (! $this->subject instanceof CategorySubject && ($view->needsSubject() || $write->needsSubject() || $moderators->roster instanceof RosterScope)) {
            throw InvalidCategoryException::rosterRuleWithoutSubject();
        }

        $this->viewAudience = $view;
        $this->writeAudience = $write;
        $this->moderators = $moderators;
    }

    /**
     * Ties the category to a publication (a campaign's space), or unties it with null.
     *
     * @throws InvalidCategoryException when a rule still reads the roster of the publication
     */
    public function linkTo(?CategorySubject $subject): void
    {
        if (! $subject instanceof CategorySubject && ($this->viewAudience->needsSubject() || $this->writeAudience->needsSubject() || $this->moderators->roster instanceof RosterScope)) {
            throw InvalidCategoryException::rosterRuleWithoutSubject();
        }

        $this->subject = $subject;
    }

    /**
     * Read-only by hand: nobody writes in it but the forum's moderators, and the forum never
     * lifts it by itself.
     */
    public function makeReadOnly(): void
    {
        $this->readOnlyBy = ReadOnlyOrigin::Moderation;
    }

    /**
     * Read-only because of its publication, unless it is read-only by hand already (that stays).
     */
    public function makeReadOnlyBySystem(): void
    {
        $this->readOnlyBy ??= ReadOnlyOrigin::System;
    }

    /**
     * Takes posts again, whoever made it read-only.
     */
    public function allowWriting(): void
    {
        $this->readOnlyBy = null;
    }

    /**
     * Takes posts again only if the forum made it read-only; read-only by hand stays.
     */
    public function allowWritingBySystem(): void
    {
        if ($this->readOnlyBy === ReadOnlyOrigin::System) {
            $this->readOnlyBy = null;
        }
    }

    public function isReadOnly(): bool
    {
        return $this->readOnlyBy instanceof ReadOnlyOrigin;
    }

    public function readOnlyBy(): ?ReadOnlyOrigin
    {
        return $this->readOnlyBy;
    }

    public function id(): CategoryId
    {
        return $this->id;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function slug(): CategorySlug
    {
        return $this->slug;
    }

    public function description(): ?string
    {
        return $this->description;
    }

    public function position(): int
    {
        return $this->position;
    }

    public function parentId(): ?CategoryId
    {
        return $this->parentId;
    }

    public function isTopLevel(): bool
    {
        return ! $this->parentId instanceof CategoryId;
    }

    public function appearance(): CategoryAppearance
    {
        return $this->appearance;
    }

    public function viewAudience(): CategoryAudience
    {
        return $this->viewAudience;
    }

    public function writeAudience(): CategoryAudience
    {
        return $this->writeAudience;
    }

    public function moderators(): CategoryModerators
    {
        return $this->moderators;
    }

    public function subject(): ?CategorySubject
    {
        return $this->subject;
    }

    /**
     * A space of its own for a publication (a campaign). Its pages tell readers that the forum's
     * moderators may enter it as well (forum:forum.moderate reads every category).
     */
    public function isPublicationSpace(): bool
    {
        return $this->subject instanceof CategorySubject;
    }

    public function systemKey(): ?SystemCategory
    {
        return $this->systemKey;
    }

    public function isSystem(): bool
    {
        return $this->systemKey instanceof SystemCategory;
    }

    private function checkedName(string $name): string
    {
        $name = trim($name);
        if ($name === '') {
            throw InvalidCategoryException::blankName();
        }

        if (mb_strlen($name) > self::MAX_NAME_LENGTH) {
            throw InvalidCategoryException::nameTooLong(self::MAX_NAME_LENGTH);
        }

        return $name;
    }

    private function checkedDescription(?string $description): ?string
    {
        $description = trim($description ?? '');
        if (mb_strlen($description) > self::MAX_DESCRIPTION_LENGTH) {
            throw InvalidCategoryException::descriptionTooLong(self::MAX_DESCRIPTION_LENGTH);
        }

        return $description === '' ? null : $description;
    }

    private function checkedPosition(int $position): int
    {
        if ($position < 0) {
            throw InvalidCategoryException::negativePosition($position);
        }

        return $position;
    }
}
