<?php

declare(strict_types=1);

namespace Modules\Forum\Application\Search;

use App\Domain\Search\Enums\SearchAudience;
use Modules\Forum\Domain\Entities\Category;
use Modules\Forum\Domain\Enums\CategoryAudienceKind;
use Modules\Forum\Domain\ValueObjects\CategoryAudience;
use Modules\Forum\Domain\ValueObjects\CategoryId;

/**
 * The search audience of what a category holds. The site search knows three levels (anyone, any
 * account, association members), so only a read rule that is one of them can be indexed: roles and
 * a campaign's roster are not expressible and fail closed (no audience, nothing indexed).
 *
 * Reading a subcategory needs reading its parent too, so the documents of a subcategory take the
 * narrower of the two levels; a parent that is missing, is not top level or cannot be expressed
 * leaves them out, however wide the subcategory's own rule is.
 */
final class ForumSearchAudiences
{
    /**
     * @param  array<string, Category>  $categories  every category by id
     */
    public function for(Category $category, array $categories): ?SearchAudience
    {
        $own = $this->level($category->viewAudience());
        if ($own === null) {
            return null;
        }

        $parentId = $category->parentId();
        if (! $parentId instanceof CategoryId) {
            return self::audience($own);
        }

        $parent = $categories[$parentId->value] ?? null;
        $inherited = $parent instanceof Category && $parent->isTopLevel() ? $this->level($parent->viewAudience()) : null;

        return $inherited === null ? null : self::audience(max($own, $inherited));
    }

    /**
     * How narrow the rule is: 0 anyone, 1 any account, 2 association members; null when no audience says it.
     */
    private function level(CategoryAudience $audience): ?int
    {
        return match ($audience->kind) {
            CategoryAudienceKind::Everyone => 0,
            CategoryAudienceKind::SignedIn => 1,
            CategoryAudienceKind::AssociationMembers => 2,
            default => null,
        };
    }

    private static function audience(int $level): SearchAudience
    {
        return match ($level) {
            0 => SearchAudience::Public,
            1 => SearchAudience::Authenticated,
            default => SearchAudience::Members,
        };
    }
}
