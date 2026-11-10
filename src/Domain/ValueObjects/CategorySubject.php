<?php

declare(strict_types=1);

namespace Modules\Forum\Domain\ValueObjects;

use Modules\Forum\Domain\Exceptions\InvalidCategoryException;

/**
 * The publication a category belongs to (a campaign's own space): its type and id as the core's
 * publication sources name them. Roster rules read the roster of this record.
 */
final readonly class CategorySubject
{
    public const int MAX_LENGTH = 64;

    public string $id;

    /**
     * @throws InvalidCategoryException
     */
    public function __construct(
        public string $type,
        string $id,
    ) {
        if (strlen($type) > self::MAX_LENGTH || preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $type) !== 1) {
            throw InvalidCategoryException::invalidSubject($type, $id);
        }

        $id = trim($id);
        if ($id === '' || strlen($id) > self::MAX_LENGTH) {
            throw InvalidCategoryException::invalidSubject($type, $id);
        }

        $this->id = $id;
    }

    public function equals(self $other): bool
    {
        return $this->type === $other->type && $this->id === $other->id;
    }
}
