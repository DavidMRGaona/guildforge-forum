<?php

declare(strict_types=1);

namespace Modules\Forum\Infrastructure\Profiles;

use App\Application\Profiles\Contracts\ProfileStatInterface;
use App\Application\Profiles\DTOs\ProfileOwnerDTO;
use App\Application\Profiles\DTOs\ProfileStatDTO;
use Illuminate\Contracts\Auth\Authenticatable;
use Modules\Forum\Application\Services\ProfileActivity;

/**
 * «Temas abiertos» on a public profile (forum#135): the visible topics the person opened that the
 * viewer may read. Without a list of one person's topics in the forum, it goes without a link.
 */
final readonly class TopicsProfileStat implements ProfileStatInterface
{
    public const string KEY = 'forum.topics';

    public function __construct(
        private ProfileActivity $activity,
    ) {}

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        $text = __('forum::messages.profile.topics');

        return is_string($text) ? $text : self::KEY;
    }

    public function order(): int
    {
        return 20;
    }

    public function enabledByDefault(): bool
    {
        return true;
    }

    public function isAvailable(): bool
    {
        return true;
    }

    public function stat(ProfileOwnerDTO $owner, ?Authenticatable $viewer): ?ProfileStatDTO
    {
        $count = $this->activity->topicCount($owner->id, $viewer);

        return $count === null ? null : new ProfileStatDTO($count);
    }
}
