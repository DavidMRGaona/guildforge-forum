<?php

declare(strict_types=1);

namespace Modules\Forum\Domain\ValueObjects;

use Modules\Forum\Domain\Exceptions\InvalidCategoryException;

/**
 * Role ids as category rules keep them: the core's role UUIDs, lowercase, sorted and once each.
 * Ids, not names, so renaming a role keeps its rules; a deleted role simply matches nobody.
 */
final class RoleIds
{
    /**
     * @param  array<mixed>  $ids
     * @return list<string>
     *
     * @throws InvalidCategoryException for an id that is not a UUID
     */
    public static function normalize(array $ids): array
    {
        $normalized = [];
        foreach ($ids as $id) {
            $value = is_string($id) ? AccountId::normalize($id) : null;
            if ($value === null) {
                throw InvalidCategoryException::invalidRoleId(is_scalar($id) ? (string) $id : get_debug_type($id));
            }

            $normalized[$value] = true;
        }

        $normalized = array_keys($normalized);
        sort($normalized, SORT_STRING);

        return $normalized;
    }
}
