<?php

declare(strict_types=1);

namespace Modules\Forum\Application\Contracts;

use Modules\Forum\Domain\Entities\Category;
use Modules\Forum\Domain\Entities\Discussion;

/**
 * The public addresses of the forum: `/foro`, `/foro/c/<category>` and
 * `/foro/t/<number>-<slug>[/<post number>]`.
 */
interface ForumUrlsInterface
{
    /**
     * A site path (or an absolute URL) to the discussion, at a post when a number is given.
     */
    public function discussion(Discussion $discussion, ?int $postNumber = null, bool $absolute = false): string;

    /**
     * The same, from what a list row holds.
     */
    public function discussionFrom(int $number, string $title, ?int $postNumber = null, bool $absolute = false): string;

    public function category(Category $category, bool $absolute = false): string;

    public function index(bool $absolute = false): string;
}
