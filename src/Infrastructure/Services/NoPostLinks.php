<?php

declare(strict_types=1);

namespace Modules\Forum\Infrastructure\Services;

use Modules\Forum\Application\Contracts\PostLinksInterface;
use Modules\Forum\Domain\Entities\Discussion;
use Modules\Forum\Domain\Entities\Post;

/**
 * Until the forum pages exist, posts have no address: quotes show without a link.
 */
final class NoPostLinks implements PostLinksInterface
{
    public function urlFor(Post $post, Discussion $discussion): ?string
    {
        return null;
    }
}
