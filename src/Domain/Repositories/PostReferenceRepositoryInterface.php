<?php

declare(strict_types=1);

namespace Modules\Forum\Domain\Repositories;

use Modules\Forum\Domain\ValueObjects\PostId;

/**
 * Which posts each post points at (by «#N» or by quoting them), kept whole for each post that
 * points. A post pointing at itself is never kept.
 */
interface PostReferenceRepositoryInterface
{
    /**
     * Replaces what the post points at.
     *
     * @param  list<string>  $referenced  ids of the posts it references with «#N»
     * @param  list<string>  $quoted  ids of the posts it quotes
     */
    public function replaceFor(PostId $source, array $referenced, array $quoted): void;

    /**
     * The posts it points at, each once, in no particular order.
     *
     * @return list<string>
     */
    public function targetsOf(PostId $source): array;

    /**
     * How many visible posts point at each of these posts, by the discussion each of those
     * posts is in (a post pointing twice counts once). Posts nobody points at are left out.
     *
     * @param  list<string>  $targets
     * @return array<string, array<string, int>> by target id, then discussion id
     */
    public function countsFor(array $targets): array;

    /**
     * The visible posts that point at this one, oldest first.
     *
     * @return list<array{postId: string, discussionId: string}>
     */
    public function visibleSourcesOf(PostId $target): array;
}
