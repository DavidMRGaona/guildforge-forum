<?php

declare(strict_types=1);

namespace Modules\Forum\Tests\Feature\Rendering;

use Illuminate\Support\Facades\DB;
use Modules\Forum\Application\Contracts\ForumUrlsInterface;
use Modules\Forum\Application\Services\ForumWriter;
use Modules\Forum\Application\Services\PostRenderer;
use Modules\Forum\Domain\Entities\Discussion;
use Modules\Forum\Domain\Entities\Post;
use Modules\Forum\Domain\Repositories\DiscussionRepositoryInterface;
use Modules\Forum\Domain\ValueObjects\PostBody;
use Modules\Forum\Tests\Support\BuildsForumContent;
use Tests\Support\Modules\ModuleTestCase;
use Tests\Support\Time\UsesUtcStorage;

/**
 * A reference shows the post it points at as each reader may know it: a link with its current
 * number and place for whoever may read it, the bare number for anyone else, and «#?» once
 * the post is gone.
 */
final class PostReferenceRenderingTest extends ModuleTestCase
{
    use BuildsForumContent;
    use UsesUtcStorage;

    private const string MISSING = '0b6f1d7e-3c1a-4f0e-9a51-6f1e2d3c4b5a';

    protected ?string $moduleName = 'forum';

    protected bool $autoEnableModule = true;

    private Discussion $discussion;

    private Post $target;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useUtcStorage();
        [$this->discussion] = $this->aDiscussion($this->aCategory(), title: 'Sesión <b>del</b> viernes');
        $this->discussion = $this->reload($this->discussion);
        $this->target = $this->aReply($this->discussion, '2026-11-10 19:00:00');
    }

    public function test_a_reader_who_may_read_it_gets_a_link_to_its_place(): void
    {
        $number = $this->target->number()?->value;
        $url = app(ForumUrlsInterface::class)->discussion($this->discussion, (int) $number);
        $preview = route('forum.posts.preview', ['post' => $this->target->id()->value]);

        $html = $this->render($this->target->id()->value);

        $this->assertSame(
            '<p>Mira <a class="forum-ref" href="'.e($url).'" data-post-id="'.$this->target->id()->value.'" data-preview-url="'.e($preview).'" title="Sesión &lt;b&gt;del&lt;/b&gt; viernes">#2</a> ahora</p>',
            $html,
        );
    }

    public function test_a_hidden_post_shows_as_its_bare_number(): void
    {
        app(ForumWriter::class)->changePost($this->target->id(), static fn (Post $post) => $post->hide());

        $html = $this->render($this->target->id()->value);

        $this->assertSame('<p>Mira <span class="forum-ref">#2</span> ahora</p>', $html);
        $this->assertStringNotContainsString($this->target->id()->value, $html);
    }

    public function test_a_post_that_is_gone_shows_as_unknown(): void
    {
        $this->assertSame('<p>Mira <span class="forum-ref">#?</span> ahora</p>', $this->render(self::MISSING));
    }

    public function test_references_and_quotes_of_a_page_are_looked_up_at_once(): void
    {
        $quoted = $this->aReply($this->discussion, '2026-11-10 20:00:00', text: 'Lo que dijo');
        $body = new PostBody(['type' => 'doc', 'content' => [
            ['type' => 'paragraph', 'content' => [['type' => 'postReference', 'attrs' => ['postId' => $this->target->id()->value]]]],
            ['type' => 'postQuote', 'attrs' => ['postId' => $quoted->id()->value], 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Lo que dijo']]]]],
        ]], 'Lo que dijo');

        DB::enableQueryLog();
        app(PostRenderer::class)->toHtml([$body], null);
        $lookups = array_filter(DB::getQueryLog(), static fn (array $query): bool => str_contains($query['query'], 'from "forum_posts"'));
        DB::disableQueryLog();

        $this->assertCount(1, $lookups);
    }

    private function render(string $postId): string
    {
        $body = new PostBody(['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [
            ['type' => 'text', 'text' => 'Mira '],
            ['type' => 'postReference', 'attrs' => ['postId' => $postId]],
            ['type' => 'text', 'text' => ' ahora'],
        ]]]], 'Mira  ahora');

        return app(PostRenderer::class)->toHtml([$body], null)[0];
    }

    private function reload(Discussion $discussion): Discussion
    {
        $found = app(DiscussionRepositoryInterface::class)->find($discussion->id());
        $this->assertInstanceOf(Discussion::class, $found);

        return $found;
    }
}
