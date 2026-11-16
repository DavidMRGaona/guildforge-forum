<?php

declare(strict_types=1);

namespace Modules\Forum\Infrastructure\Profiles;

use App\Application\Exceptions\NothingToDrawException;
use App\Application\Profiles\Contracts\ProfileSectionInterface;
use App\Application\Profiles\DTOs\ProfileOwnerDTO;
use App\Application\Services\TimezoneServiceInterface;
use App\Domain\Profiles\ProfileRegion;
use Illuminate\Contracts\Auth\Authenticatable;
use Modules\Forum\Application\Contracts\ForumUrlsInterface;
use Modules\Forum\Application\DTOs\ProfilePostDTO;
use Modules\Forum\Application\Services\ProfileActivity;

/**
 * «Últimos mensajes en el foro» on a public profile (forum#135): the person's latest messages the
 * viewer may read in the forum itself (ProfileActivity), each with its topic, category, date and
 * an excerpt in plain text, linking to the message. Nothing to show draws nothing.
 */
final readonly class LatestPostsProfileSection implements ProfileSectionInterface
{
    public const string KEY = 'forum.latest-posts';

    public const int LIMIT = 5;

    public function __construct(
        private ProfileActivity $activity,
        private ForumUrlsInterface $urls,
        private TimezoneServiceInterface $timezone,
    ) {}

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return $this->text('forum::messages.profile.latest_posts');
    }

    public function description(): string
    {
        return $this->text('forum::messages.profile.latest_posts_description');
    }

    public function component(): string
    {
        return 'components/profile/LatestPostsSection.vue';
    }

    public function region(): ProfileRegion
    {
        return ProfileRegion::Main;
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

    public function props(ProfileOwnerDTO $owner, ?Authenticatable $viewer): array
    {
        $posts = $this->activity->latestPosts($owner->id, $viewer, self::LIMIT);
        if ($posts === []) {
            throw NothingToDrawException::make();
        }

        return ['posts' => array_map(fn (ProfilePostDTO $post): array => [
            'title' => $post->row->discussionTitle,
            'url' => $this->urls->discussionFrom($post->row->discussionNumber, $post->row->discussionTitle, $post->row->number),
            'category' => $post->category->name(),
            'categoryUrl' => $this->urls->category($post->category),
            'excerpt' => $post->excerpt,
            'publishedAt' => $this->timezone->iso($post->row->publishedAt),
            'publishedLabel' => $this->timezone->format($post->row->publishedAt),
        ], $posts)];
    }

    private function text(string $key): string
    {
        $text = __($key);

        return is_string($text) ? $text : $key;
    }
}
