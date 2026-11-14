<?php

declare(strict_types=1);

namespace Modules\Forum\Tests\Feature\Console;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Modules\Forum\Console\Commands\IndexPostReferencesCommand;
use Modules\Forum\Tests\Support\BuildsForumContent;
use Tests\Support\Modules\ModuleTestCase;
use Tests\Support\Time\UsesUtcStorage;

/**
 * Fills the references of posts written before they were kept (their quotes): reading every
 * post's body again, so running it twice changes nothing.
 */
final class IndexPostReferencesCommandTest extends ModuleTestCase
{
    use BuildsForumContent;
    use UsesUtcStorage;

    protected ?string $moduleName = 'forum';

    protected bool $autoEnableModule = true;

    public function test_it_indexes_the_quotes_of_existing_posts_once(): void
    {
        $this->useUtcStorage();
        [$discussion, $first] = $this->aDiscussion($this->aCategory());
        $quoting = $this->aReply($discussion, '2026-11-10 19:00:00');
        $this->aReply($discussion, '2026-11-10 19:30:00', text: 'Sin citas');
        // As a post stored before the table existed: the body holds the quote, the table nothing
        DB::table('forum_posts')->where('id', $quoting->id()->value)->update(['body' => json_encode(['type' => 'doc', 'content' => [
            ['type' => 'postQuote', 'attrs' => ['postId' => $first->id()->value], 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Primer mensaje']]]]],
        ]], JSON_THROW_ON_ERROR)]);
        DB::table('forum_post_references')->delete();

        // The module is enabled after Artisan started, so its commands are added by hand
        $this->app->make(Kernel::class)->registerCommand($this->app->make(IndexPostReferencesCommand::class));

        $this->artisanCommand('forum:index-post-references')->expectsOutputToContain('Read 3 posts.')->assertSuccessful();
        $this->artisanCommand('forum:index-post-references')->assertSuccessful();

        $rows = DB::table('forum_post_references')->get(['source_post_id', 'target_post_id', 'kind'])
            ->map(static fn (object $row): array => [strtolower((string) $row->source_post_id), strtolower((string) $row->target_post_id), (string) $row->kind])
            ->all();
        $this->assertSame([[$quoting->id()->value, $first->id()->value, 'quote']], array_values($rows));
    }
}
