<?php

declare(strict_types=1);

namespace Modules\Forum\Console\Commands;

use Illuminate\Console\Command;
use Modules\Forum\Application\Content\DocumentReferences;
use Modules\Forum\Domain\Repositories\PostReferenceRepositoryInterface;
use Modules\Forum\Domain\ValueObjects\PostBody;
use Modules\Forum\Domain\ValueObjects\PostId;
use Modules\Forum\Infrastructure\Persistence\Eloquent\Models\PostModel;

/**
 * Fills what posts written before forum#122 point at (their quotes) from their stored bodies.
 * Every post's rows are written again from its body, so running it twice changes nothing.
 */
final class IndexPostReferencesCommand extends Command
{
    protected $signature = 'forum:index-post-references';

    protected $description = 'Index which posts every forum post references or quotes, from its stored body';

    public function handle(PostReferenceRepositoryInterface $references): int
    {
        $read = 0;
        PostModel::query()->select(['id', 'body'])->orderBy('id')->chunk(500, function ($posts) use ($references, &$read): void {
            foreach ($posts as $post) {
                $found = DocumentReferences::of(new PostBody($post->body, ''));
                $references->replaceFor(PostId::fromString((string) $post->id), $found->referencedPostIds, $found->quotedPostIds);
                $read++;
            }
        });

        $this->info(sprintf('Read %d posts.', $read));

        return self::SUCCESS;
    }
}
