<?php

declare(strict_types=1);

namespace Modules\Forum\Application\DTOs;

use App\Application\Profiles\DTOs\ProfileCardDTO;
use Modules\Forum\Domain\Entities\Discussion;

/**
 * The card a post's author opens with (forum#134), for one reader: the core's profile card when the
 * reader may see the profile, and what the forum adds. Never the account's name or email. The id
 * only goes where the page already carries it: in the addresses of the filter by author and of
 * «Dejar de ignorar», never shown.
 */
final readonly class AuthorCardDTO
{
    /**
     * @param  ProfileCardDTO|null  $profile  null when the reader may not see the profile (or it failed)
     * @param  string|null  $title  the title the forum's moderation gave the person
     * @param  int|null  $postCount  the person's messages the reader may read in the whole forum; null for none
     * @param  int  $topicCount  the person's messages the filter by author lists in the post's discussion
     * @param  bool|null  $blocked  whether the reader ignores the person; null when they cannot (a guest,
     *                              their own post, the forum's account or an account that is gone)
     */
    public function __construct(
        public string $authorId,
        public Discussion $discussion,
        public ?ProfileCardDTO $profile,
        public ?string $title,
        public ?int $postCount,
        public int $topicCount,
        public ?bool $blocked,
    ) {}
}
