<?php

declare(strict_types=1);

namespace Modules\Forum\Tests\Support;

use App\Application\Profiles\Services\ProfileBadgesInterface;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * The core's badges, unchanged, remembering which accounts each call asked about: what the forum
 * sends to the core, before the core's own profile rule filters anybody.
 */
final class RecordingProfileBadges implements ProfileBadgesInterface
{
    /** @var list<list<string>> */
    public array $asked = [];

    public function __construct(
        private readonly ProfileBadgesInterface $badges,
    ) {}

    public function forAuthors(array $userIds, ?Authenticatable $viewer): array
    {
        $this->asked[] = $userIds;

        return $this->badges->forAuthors($userIds, $viewer);
    }

    /**
     * Every account asked about, in any call.
     *
     * @return list<string>
     */
    public function everyAccountAsked(): array
    {
        return array_values(array_unique(array_merge([], ...$this->asked)));
    }
}
