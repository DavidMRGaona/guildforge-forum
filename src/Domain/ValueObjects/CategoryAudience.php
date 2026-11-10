<?php

declare(strict_types=1);

namespace Modules\Forum\Domain\ValueObjects;

use Modules\Forum\Domain\Enums\CategoryAudienceKind;
use Modules\Forum\Domain\Exceptions\InvalidCategoryException;
use ValueError;

/**
 * One rule of a category: who may read it, or who may write in it.
 */
final readonly class CategoryAudience
{
    /** @var list<string> */
    public array $roleIds;

    /**
     * @param  array<mixed>  $roleIds  the roles of a Roles audience (none for any other kind)
     *
     * @throws InvalidCategoryException
     */
    public function __construct(
        public CategoryAudienceKind $kind,
        array $roleIds = [],
    ) {
        $roleIds = RoleIds::normalize($roleIds);

        if ($kind === CategoryAudienceKind::Roles && $roleIds === []) {
            throw InvalidCategoryException::rolesAudienceWithoutRoles();
        }

        if ($kind !== CategoryAudienceKind::Roles && $roleIds !== []) {
            throw InvalidCategoryException::rolesOutsideARolesAudience($kind->value);
        }

        $this->roleIds = $roleIds;
    }

    public static function everyone(): self
    {
        return new self(CategoryAudienceKind::Everyone);
    }

    public static function signedIn(): self
    {
        return new self(CategoryAudienceKind::SignedIn);
    }

    public static function associationMembers(): self
    {
        return new self(CategoryAudienceKind::AssociationMembers);
    }

    /**
     * @param  array<mixed>  $roleIds
     */
    public static function roles(array $roleIds): self
    {
        return new self(CategoryAudienceKind::Roles, $roleIds);
    }

    public static function roster(): self
    {
        return new self(CategoryAudienceKind::Roster);
    }

    public static function rosterLeaders(): self
    {
        return new self(CategoryAudienceKind::RosterLeaders);
    }

    /**
     * @param  array<mixed>  $data
     *
     * @throws InvalidCategoryException when it is not a rule this module wrote
     */
    public static function fromArray(array $data): self
    {
        $kind = $data['kind'] ?? null;
        $roleIds = $data['role_ids'] ?? [];

        try {
            return new self(CategoryAudienceKind::from(is_string($kind) ? $kind : ''), is_array($roleIds) ? $roleIds : throw InvalidCategoryException::unreadableRule());
        } catch (ValueError) {
            throw InvalidCategoryException::unreadableRule();
        }
    }

    /**
     * @return array{kind: string, role_ids: list<string>}
     */
    public function toArray(): array
    {
        return ['kind' => $this->kind->value, 'role_ids' => $this->roleIds];
    }

    public function allowsGuests(): bool
    {
        return $this->kind === CategoryAudienceKind::Everyone;
    }

    public function needsSubject(): bool
    {
        return $this->kind->needsSubject();
    }

    /**
     * Whether this audience, as a subcategory's, reaches no further than the parent's.
     *
     * Reading a subcategory always requires reading its parent too, so the subcategory shows to
     * whoever is in both. What is refused here is what would read as wider: a broader kind (anyone
     * under accounts, accounts under a group), more roles than the parent names, or the whole
     * roster under its leaders. Two different groups (members under some roles) may overlap and
     * are allowed: only people in both see it.
     */
    public function isWithin(self $parent): bool
    {
        if ($this->kind->reach() !== $parent->kind->reach()) {
            return $this->kind->reach() < $parent->kind->reach();
        }

        return match (true) {
            $this->kind === CategoryAudienceKind::Roles && $parent->kind === CategoryAudienceKind::Roles => array_diff($this->roleIds, $parent->roleIds) === [],
            $this->kind === CategoryAudienceKind::Roster && $parent->kind === CategoryAudienceKind::RosterLeaders => false,
            default => true,
        };
    }
}
