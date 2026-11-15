<?php

declare(strict_types=1);

namespace Modules\Forum\Infrastructure\Profiles;

use App\Application\Profiles\Contracts\ProfileStatInterface;
use App\Application\Profiles\DTOs\ProfileOwnerDTO;
use App\Application\Profiles\DTOs\ProfileStatDTO;
use Illuminate\Contracts\Auth\Authenticatable;
use Modules\Forum\Application\Services\ProfileActivity;

/**
 * «Mensajes en el foro» on a public profile (forum#135): the person's visible messages the viewer
 * may read, counted as the section lists them. The forum has no list of one person's messages to
 * link to (its filter by author is per topic), so the number goes without a link.
 */
final readonly class PostsProfileStat implements ProfileStatInterface
{
    public const string KEY = 'forum.posts';

    public function __construct(
        private ProfileActivity $activity,
    ) {}

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        $text = __('forum::messages.profile.posts');

        return is_string($text) ? $text : self::KEY;
    }

    public function order(): int
    {
        return 10;
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
        $count = $this->activity->postCount($owner->id, $viewer);

        return $count === null ? null : new ProfileStatDTO($count);
    }
}
