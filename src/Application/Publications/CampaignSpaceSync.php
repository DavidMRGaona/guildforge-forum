<?php

declare(strict_types=1);

namespace Modules\Forum\Application\Publications;

use App\Application\Publications\Contracts\PublicationRosterInterface;
use App\Application\Publications\DTOs\PublicationDTO;
use App\Application\Publications\Enums\PublicationState;
use App\Application\Publications\Services\PublicationSourceRegistryInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Forum\Application\Services\CategoryManager;
use Modules\Forum\Domain\Entities\Category;
use Modules\Forum\Domain\Enums\RosterScope;
use Modules\Forum\Domain\Enums\SystemCategory;
use Modules\Forum\Domain\Repositories\CategoryRepositoryInterface;
use Modules\Forum\Domain\Repositories\SubjectDiscussionRepositoryInterface;
use Modules\Forum\Domain\ValueObjects\CategoryAudience;
use Modules\Forum\Domain\ValueObjects\CategoryId;
use Modules\Forum\Domain\ValueObjects\CategoryModerators;
use Modules\Forum\Domain\ValueObjects\CategorySlug;
use Modules\Forum\Domain\ValueObjects\CategorySubject;
use Modules\Forum\Domain\ValueObjects\PublicationKey;

/**
 * Each campaign gets a private space: a subcategory of «Campañas» tied to the campaign
 * (campaign.<id>), read and written in by its roster (players and directors) and moderated by its
 * directors. Who that is is asked to the core's roster every time (CategoryAccessPolicy), never
 * copied, so joining or leaving the campaign changes access at once; a roster that cannot be read
 * lets nobody in but the forum's moderators.
 *
 * - It is created when the campaign is published and active, never for one already over.
 * - Over or cancelled, the space becomes read-only (its roster still reads it). Taken down or
 *   deleted, its roster is gone, so only the forum's moderators see it; it is made read-only as
 *   well. Nothing in it is ever deleted.
 * - Active again, the forum lifts the read-only mark only if it set it: one set by hand stays.
 * - Its name follows the campaign's title.
 *
 * Checks of one campaign run one after the other (its publication record is locked).
 */
final readonly class CampaignSpaceSync
{
    public const string TYPE = 'campaign';

    /** Room left for the id in a space's slug */
    private const int SLUG_TITLE_LENGTH = 50;

    public function __construct(
        private PublicationSourceRegistryInterface $publications,
        private CategoryRepositoryInterface $categories,
        private CategoryManager $manager,
        private SubjectDiscussionRepositoryInterface $records,
    ) {}

    public function sync(PublicationKey $key): void
    {
        $source = $this->publications->source($key->type);
        if ($key->type !== self::TYPE || ! $source instanceof PublicationRosterInterface) {
            return;
        }

        if (mb_strlen($key->id) > CategorySubject::MAX_LENGTH) {
            return;
        }

        // A failing source throws and the check is retried: never taken for a campaign taken down
        $publication = $source->find($key->id);
        $subject = new CategorySubject($key->type, $key->id);

        DB::transaction(function () use ($key, $subject, $publication): void {
            $this->records->lockOrCreate($key);

            $active = $publication instanceof PublicationDTO && $publication->state === PublicationState::Active;
            $space = $this->categories->findBySubject($subject);

            if (! $space instanceof Category) {
                if ($active) {
                    $this->open($subject, $publication);
                }

                return;
            }

            $before = [$space->readOnlyBy(), $space->name()];

            if ($active) {
                $space->allowWritingBySystem();
                $space->rename($this->name($publication), $space->slug(), $space->description());
            } else {
                $space->makeReadOnlyBySystem();
            }

            if ($before !== [$space->readOnlyBy(), $space->name()]) {
                $this->manager->save($space);
            }
        });
    }

    private function open(CategorySubject $subject, PublicationDTO $publication): void
    {
        $parent = $this->categories->findBySystemKey(SystemCategory::Campaigns);
        if (! $parent instanceof Category) {
            Log::warning('[Forum] System category missing: campaign space not created', ['campaign' => $subject->id]);

            return;
        }

        $space = new Category(
            CategoryId::generate(),
            $this->name($publication),
            CategorySlug::fromName(mb_substr($publication->title, 0, self::SLUG_TITLE_LENGTH).' '.substr($subject->id, 0, 8)),
            __('forum::messages.spaces.description'),
            $this->categories->nextPosition(),
            $parent->id(),
            viewAudience: CategoryAudience::roster(),
            writeAudience: CategoryAudience::roster(),
            moderators: new CategoryModerators([], RosterScope::Leaders),
            subject: $subject,
        );

        $this->manager->save($space);
    }

    private function name(PublicationDTO $publication): string
    {
        $title = trim($publication->title);

        return mb_strlen($title) <= Category::MAX_NAME_LENGTH ? $title : rtrim(mb_substr($title, 0, Category::MAX_NAME_LENGTH - 1)).'…';
    }
}
