<?php

declare(strict_types=1);

namespace Modules\Forum\Domain\Entities;

use DateTimeImmutable;
use Modules\Forum\Domain\Exceptions\InvalidCharacterException;
use Modules\Forum\Domain\ValueObjects\AccountId;
use Modules\Forum\Domain\ValueObjects\CategoryId;
use Modules\Forum\Domain\ValueObjects\CharacterId;

/**
 * A character an account writes as: a name, a short description and a portrait (without one,
 * its initials show). It belongs to one account for good. Tied to a category, it is offered
 * only there (and in its subcategories) and only whoever reads that category sees it.
 *
 * A character with posts is retired, never deleted by its owner: its posts keep showing it, and
 * nobody writes as it any more. Its owner may bring it back, unless moderation retired it.
 */
final class Character
{
    public const int MAX_NAME_LENGTH = 60;

    public const int MAX_DESCRIPTION_LENGTH = 300;

    /** The folder portraits are stored in, claimed by the forum's portrait media source */
    public const string PORTRAIT_FOLDER = 'forum/characters/';

    private const string PORTRAIT_PATTERN = '#^forum/characters/\d{4}/\d{2}/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\.(jpg|png|webp|gif)$#';

    private readonly string $ownerId;

    private string $name;

    private ?string $description;

    /**
     * @param  string  $ownerId  the account that writes as it
     * @param  string|null  $portraitPath  where its portrait is stored, under PORTRAIT_FOLDER
     * @param  bool  $retirementLocked  moderation retired it: only moderation brings it back
     *
     * @throws InvalidCharacterException
     */
    public function __construct(
        private readonly CharacterId $id,
        string $ownerId,
        string $name,
        ?string $description,
        private ?string $portraitPath,
        private ?CategoryId $categoryId,
        private readonly DateTimeImmutable $createdAt,
        private DateTimeImmutable $updatedAt,
        private ?DateTimeImmutable $retiredAt = null,
        private bool $retirementLocked = false,
    ) {
        $this->ownerId = AccountId::normalize($ownerId)
            ?? throw InvalidCharacterException::because('owner', 'owner', "'{$ownerId}' is not an account id.");
        $this->name = $this->checkedName($name);
        $this->description = $this->checkedDescription($description);
        $this->checkPortrait($portraitPath);

        if ($retirementLocked && ! $retiredAt instanceof DateTimeImmutable) {
            $this->retirementLocked = false;
        }
    }

    /**
     * @throws InvalidCharacterException
     */
    public static function create(CharacterId $id, string $ownerId, string $name, ?string $description, ?CategoryId $categoryId, DateTimeImmutable $at): self
    {
        return new self($id, $ownerId, $name, $description, null, $categoryId, $at, $at);
    }

    /**
     * New name, description and category. The portrait changes on its own.
     *
     * @throws InvalidCharacterException
     */
    public function change(string $name, ?string $description, ?CategoryId $categoryId, DateTimeImmutable $at): void
    {
        $this->name = $this->checkedName($name);
        $this->description = $this->checkedDescription($description);
        $this->categoryId = $categoryId;
        $this->updatedAt = $at;
    }

    /**
     * A new portrait (its stored path), or none.
     *
     * @throws InvalidCharacterException
     */
    public function changePortrait(?string $path, DateTimeImmutable $at): void
    {
        $this->checkPortrait($path);
        $this->portraitPath = $path;
        $this->updatedAt = $at;
    }

    /**
     * Nobody writes as it from now on; its posts keep showing it. Retired by moderation, its
     * owner cannot bring it back; moderation may also lock one its owner retired.
     *
     * @throws InvalidCharacterException when it is retired already (and locked, for moderation)
     */
    public function retire(DateTimeImmutable $at, bool $byModeration): void
    {
        if ($this->isRetired() && (! $byModeration || $this->retirementLocked)) {
            throw InvalidCharacterException::because(InvalidCharacterException::ALREADY_RETIRED, 'retired', "Character {$this->id->value} is retired already.");
        }

        $this->retiredAt ??= $at;
        $this->retirementLocked = $this->retirementLocked || $byModeration;
        $this->updatedAt = $at;
    }

    /**
     * Writable again.
     *
     * @throws InvalidCharacterException unless it is retired, or when moderation retired it and its owner asks
     */
    public function restore(DateTimeImmutable $at, bool $byModeration): void
    {
        if (! $this->isRetired()) {
            throw InvalidCharacterException::because(InvalidCharacterException::NOT_RETIRED, 'retired', "Character {$this->id->value} is not retired.");
        }

        if ($this->retirementLocked && ! $byModeration) {
            throw InvalidCharacterException::because(InvalidCharacterException::LOCKED, 'retired', "Character {$this->id->value} was retired by moderation.");
        }

        $this->retiredAt = null;
        $this->retirementLocked = false;
        $this->updatedAt = $at;
    }

    /**
     * What an anonymized account leaves of it: the name stays (its posts still read), the
     * description and portrait go. The portrait file is left to the media library, which finds
     * it unused.
     */
    public function stripPersonalDetails(DateTimeImmutable $at): void
    {
        $this->description = null;
        $this->portraitPath = null;
        $this->updatedAt = $at;
    }

    /**
     * Up to two letters from its name, shown when it has no portrait («Arwen Undómiel» → «AU»).
     */
    public function initials(): string
    {
        $words = preg_split('/[\s\-]+/u', $this->name, -1, PREG_SPLIT_NO_EMPTY) ?: [$this->name];
        $letters = '';
        foreach (array_slice($words, 0, 2) as $word) {
            $letters .= mb_strtoupper(mb_substr($word, 0, 1));
        }

        return $letters;
    }

    public function isOwnedBy(string $accountId): bool
    {
        return AccountId::normalize($accountId) === $this->ownerId;
    }

    public function isRetired(): bool
    {
        return $this->retiredAt instanceof DateTimeImmutable;
    }

    public function id(): CharacterId
    {
        return $this->id;
    }

    public function ownerId(): string
    {
        return $this->ownerId;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function description(): ?string
    {
        return $this->description;
    }

    public function portraitPath(): ?string
    {
        return $this->portraitPath;
    }

    public function categoryId(): ?CategoryId
    {
        return $this->categoryId;
    }

    public function retiredAt(): ?DateTimeImmutable
    {
        return $this->retiredAt;
    }

    public function isRetirementLocked(): bool
    {
        return $this->retirementLocked;
    }

    public function createdAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function updatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /**
     * @throws InvalidCharacterException
     */
    private function checkedName(string $name): string
    {
        $name = trim((string) preg_replace('/\s+/u', ' ', $name));
        $length = mb_strlen($name);

        if ($length === 0 || $length > self::MAX_NAME_LENGTH) {
            throw InvalidCharacterException::because(InvalidCharacterException::NAME, 'name', 'A character needs a name of 1 to '.self::MAX_NAME_LENGTH.' characters.');
        }

        return $name;
    }

    /**
     * @throws InvalidCharacterException
     */
    private function checkedDescription(?string $description): ?string
    {
        $description = $description === null ? '' : trim((string) preg_replace("/[ \t]+/u", ' ', str_replace("\r\n", "\n", $description)));
        if (mb_strlen($description) > self::MAX_DESCRIPTION_LENGTH) {
            throw InvalidCharacterException::because(InvalidCharacterException::DESCRIPTION, 'description', 'A character description has at most '.self::MAX_DESCRIPTION_LENGTH.' characters.');
        }

        return $description === '' ? null : $description;
    }

    /**
     * @throws InvalidCharacterException
     */
    private function checkPortrait(?string $path): void
    {
        if ($path !== null && preg_match(self::PORTRAIT_PATTERN, $path) !== 1) {
            throw InvalidCharacterException::because(InvalidCharacterException::PORTRAIT, 'portrait', 'A portrait is stored under '.self::PORTRAIT_FOLDER.'.');
        }
    }
}
