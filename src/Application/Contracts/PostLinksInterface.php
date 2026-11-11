<?php

declare(strict_types=1);

namespace Modules\Forum\Application\Contracts;

use Modules\Forum\Domain\Entities\Discussion;
use Modules\Forum\Domain\Entities\Post;

/**
 * Where a post can be read, for quotes that link to the original. The forum pages bind it;
 * until then posts have no address and quotes show without a link.
 */
interface PostLinksInterface
{
    public function urlFor(Post $post, Discussion $discussion): ?string;
}
