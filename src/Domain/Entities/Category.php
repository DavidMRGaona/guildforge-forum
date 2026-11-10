<?php

declare(strict_types=1);

namespace Modules\Forum\Domain\Entities;

use Modules\Forum\Domain\Exceptions\InvalidCategoryException;
use Modules\Forum\Domain\ValueObjects\CategoryId;
use Modules\Forum\Domain\ValueObjects\CategorySlug;

/**
 * A section of the forum. Its counters (discussions, posts, last activity) are not part of it:
 * the persistence layer keeps them up to date with every write.
 */
final class Category
{
    public const int MAX_NAME_LENGTH = 80;

    public const int MAX_DESCRIPTION_LENGTH = 300;

    private string $name;

    private ?string $description;

    private int $position;

    /**
     * @throws InvalidCategoryException
     */
    public function __construct(
        private readonly CategoryId $id,
        string $name,
        private CategorySlug $slug,
        ?string $description = null,
        int $position = 0,
    ) {
        $this->name = $this->checkedName($name);
        $this->description = $this->checkedDescription($description);
        $this->position = $this->checkedPosition($position);
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
