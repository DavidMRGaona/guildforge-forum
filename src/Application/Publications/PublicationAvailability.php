<?php

declare(strict_types=1);

namespace Modules\Forum\Application\Publications;

use App\Application\Publications\Contracts\PublicationSourceInterface;
use App\Application\Publications\Services\PublicationSourceRegistryInterface;
use Modules\Forum\Domain\Entities\Category;
use Modules\Forum\Domain\Enums\SystemCategory;
use Modules\Forum\Domain\ValueObjects\CategorySubject;
use Throwable;

/**
 * The one rule for what the forum builds on other modules' publications: it exists only while the
 * core's registry has a source for its publication type. The forum never asks which module is
 * enabled (modules do not depend on each other); a type nobody offers any more (its module disabled
 * or uninstalled) hides what depends on it, deleting nothing, and it shows again when a source
 * offers the type again.
 *
 * - A system category needs the source of its type («Mesas» game tables, «Campañas» campaigns,
 *   «Eventos» the core's events).
 * - A category tied to a publication (a campaign's space) needs the source of the publication's type.
 * - A subcategory needs its parent to be available as well.
 *
 * It fails closed: a registry that throws (reported) means the type is not offered. Answers are
 * remembered for the request (bound scoped), as resolving the registry's sources is not free.
 */
final class PublicationAvailability
{
    /** @var array<string, bool> */
    private array $offered = [];

    public function __construct(
        private readonly PublicationSourceRegistryInterface $publications,
    ) {}

    public function offers(string $type): bool
    {
        if (! array_key_exists($type, $this->offered)) {
            try {
                $this->offered[$type] = $this->publications->source($type) instanceof PublicationSourceInterface;
            } catch (Throwable $exception) {
                report($exception);
                $this->offered[$type] = false;
            }
        }

        return $this->offered[$type];
    }

    /**
     * The system categories some source feeds, in their order.
     *
     * @return list<SystemCategory>
     */
    public function systemCategories(): array
    {
        return array_values(array_filter(SystemCategory::cases(), fn (SystemCategory $key): bool => $this->offers($key->publicationType())));
    }

    /**
     * Whether the category is shown to anyone but the forum's moderators. A subcategory is checked
     * with its parent (the caller has it); without it only the category's own requirement is read.
     */
    public function isAvailable(Category $category, ?Category $parent = null): bool
    {
        return $this->ownSourceIsOffered($category) && (! $parent instanceof Category || $this->ownSourceIsOffered($parent));
    }

    private function ownSourceIsOffered(Category $category): bool
    {
        $systemKey = $category->systemKey();
        if ($systemKey instanceof SystemCategory && ! $this->offers($systemKey->publicationType())) {
            return false;
        }

        $subject = $category->subject();

        return ! $subject instanceof CategorySubject || $this->offers($subject->type);
    }
}
