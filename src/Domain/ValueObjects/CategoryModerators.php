<?php

declare(strict_types=1);

namespace Modules\Forum\Domain\ValueObjects;

use Modules\Forum\Domain\Enums\RosterScope;
use Modules\Forum\Domain\Exceptions\InvalidCategoryException;
use ValueError;

/**
 * Who moderates one category besides whoever moderates the whole forum: holders of some roles
 * and, in a category tied to a publication, part of its roster (a campaign's directors).
 */
final readonly class CategoryModerators
{
    /** @var list<string> */
    public array $roleIds;

    /**
     * @param  array<mixed>  $roleIds
     *
     * @throws InvalidCategoryException for a role id that is not a UUID
     */
    public function __construct(
        array $roleIds = [],
        public ?RosterScope $roster = null,
    ) {
        $this->roleIds = RoleIds::normalize($roleIds);
    }

    public static function none(): self
    {
        return new self;
    }

    /**
     * @param  array<mixed>  $data
     *
     * @throws InvalidCategoryException when it is not what this module wrote
     */
    public static function fromArray(array $data): self
    {
        $roleIds = $data['role_ids'] ?? [];
        $roster = $data['roster'] ?? null;

        if (! is_array($roleIds) || ($roster !== null && ! is_string($roster))) {
            throw InvalidCategoryException::unreadableRule();
        }

        try {
            return new self($roleIds, $roster === null ? null : RosterScope::from($roster));
        } catch (ValueError) {
            throw InvalidCategoryException::unreadableRule();
        }
    }

    /**
     * @return array{role_ids: list<string>, roster: string|null}
     */
    public function toArray(): array
    {
        return ['role_ids' => $this->roleIds, 'roster' => $this->roster?->value];
    }

    public function isEmpty(): bool
    {
        return $this->roleIds === [] && ! $this->roster instanceof RosterScope;
    }
}
