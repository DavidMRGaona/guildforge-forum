<?php

declare(strict_types=1);

namespace Modules\Forum\Domain\ValueObjects;

use InvalidArgumentException;

/**
 * A publication as the core's publication sources name it: its type (kebab-case, e.g.
 * "game-table") and its id. Its value, "<type>:<id>", is the stable key of its discussion.
 */
final readonly class PublicationKey
{
    public const int MAX_TYPE_LENGTH = 64;

    public const int MAX_ID_LENGTH = 100;

    /**
     * @throws InvalidArgumentException
     */
    public function __construct(
        public string $type,
        public string $id,
    ) {
        if (strlen($type) > self::MAX_TYPE_LENGTH || preg_match('/^[a-z][a-z0-9]*(?:-[a-z0-9]+)*\z/', $type) !== 1) {
            throw new InvalidArgumentException("Publication type '{$type}' must be kebab-case.");
        }

        if (trim($id) === '' || $id !== trim($id) || mb_strlen($id) > self::MAX_ID_LENGTH) {
            throw new InvalidArgumentException(sprintf('A publication id must have 1 to %d characters.', self::MAX_ID_LENGTH));
        }
    }

    public function value(): string
    {
        return "{$this->type}:{$this->id}";
    }

    public function equals(self $other): bool
    {
        return $this->type === $other->type && $this->id === $other->id;
    }
}
