<?php

declare(strict_types=1);

namespace Modules\Forum\Application\Contracts;

use Modules\Forum\Domain\Entities\Discussion;
use Modules\Forum\Domain\Entities\Post;

/**
 * Where a post can be read, for quotes and references that link to the original. The forum pages bind it;
 * until then posts have no address and quotes show without a link.
 */
interface PostLinksInterface
{
    public function urlFor(Post $post, Discussion $discussion): ?string;

    /** Where a post's preview is asked for (the box a reference opens); null while there is none */
    public function previewUrlFor(Post $post): ?string;
}
