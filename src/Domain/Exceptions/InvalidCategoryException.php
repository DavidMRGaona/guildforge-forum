<?php

declare(strict_types=1);

namespace Modules\Forum\Domain\Exceptions;

use DomainException;

/**
 * $reason is a stable key (snake_case) the panel turns into a message next to the right field.
 */
final class InvalidCategoryException extends DomainException
{
    private function __construct(string $message, public readonly string $reason = 'invalid')
    {
        parent::__construct($message);
    }

    public static function invalidSlug(string $slug): self
    {
        return new self("'{$slug}' is not a category slug: lowercase letters and digits joined by single dashes, 64 characters at most.");
    }

    public static function blankName(): self
    {
        return new self('A category needs a name.');
    }

    public static function nameTooLong(int $max): self
    {
        return new self("A category name has {$max} characters at most.");
    }

    public static function descriptionTooLong(int $max): self
    {
        return new self("A category description has {$max} characters at most.");
    }

    public static function negativePosition(int $position): self
    {
        return new self("A category position is not negative ({$position} given).");
    }

    public static function invalidRoleId(string $id): self
    {
        return new self("'{$id}' is not a role id.");
    }

    public static function rolesAudienceWithoutRoles(): self
    {
        return new self('A rule for some roles names at least one.');
    }

    public static function rolesOutsideARolesAudience(string $kind): self
    {
        return new self("A '{$kind}' rule names no roles.");
    }

    public static function unreadableRule(): self
    {
        return new self('The category rule cannot be read.');
    }

    public static function invalidColor(string $color): self
    {
        return new self("'{$color}' is not a colour (#rrggbb).");
    }

    public static function invalidSubject(string $type, string $id): self
    {
        return new self("'{$type}:{$id}' is not a publication.");
    }

    public static function guestsCannotWrite(): self
    {
        return new self('Only accounts write: the write rule never lets anyone in.', 'guests_cannot_write');
    }

    public static function rosterRuleWithoutSubject(): self
    {
        return new self('A rule for a roster needs the category to belong to a publication.', 'roster_without_subject');
    }

    public static function ownParent(string $id): self
    {
        return new self("Category {$id} cannot be its own parent.", 'own_parent');
    }

    public static function parentTooDeep(string $id): self
    {
        return new self("Category {$id} is a subcategory already: subcategories hold no subcategories.", 'parent_too_deep');
    }

    public static function hasSubcategories(string $id): self
    {
        return new self("Category {$id} has subcategories: it cannot become one.", 'has_subcategories');
    }

    public static function widerThanParent(string $id): self
    {
        return new self("Category {$id} would be readable by more people than its parent.", 'wider_than_parent');
    }

    public static function narrowerThanChildren(string $id): self
    {
        return new self("Category {$id} would be readable by fewer people than one of its subcategories.", 'narrower_than_children');
    }

    public static function systemCategory(string $id): self
    {
        return new self("Category {$id} is one the forum relies on: it is never deleted.", 'system_category');
    }

    public static function stillHoldsSubcategories(string $id): self
    {
        return new self("Category {$id} still holds subcategories: move or delete them first.", 'still_holds_subcategories');
    }

    public static function stillHoldsDiscussions(string $id): self
    {
        return new self("Category {$id} still holds discussions: move them to another category first.", 'still_holds_discussions');
    }

    public static function cannotMoveIntoItself(string $id): self
    {
        return new self("The discussions of category {$id} cannot move into it.", 'cannot_move_into_itself');
    }

    public static function approvalThresholdOutOfBounds(int $max): self
    {
        return new self("A category asks for 0 to {$max} approved posts.", 'approval_threshold');
    }
}
