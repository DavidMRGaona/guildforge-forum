<?php

declare(strict_types=1);

namespace Modules\Forum\Tests\Feature\Http;

use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use DateTimeImmutable;
use Inertia\Testing\AssertableInertia;
use Modules\Forum\Application\Services\ForumWriter;
use Modules\Forum\Domain\Entities\Discussion;
use Modules\Forum\Domain\Entities\Post;
use Modules\Forum\Domain\Repositories\DiscussionRepositoryInterface;
use Modules\Forum\Domain\Repositories\PostRepositoryInterface;
use Modules\Forum\Domain\ValueObjects\DiscussionTitle;
use Modules\Forum\Domain\ValueObjects\PostBody;
use Modules\Forum\Domain\ValueObjects\PostId;
use Modules\Forum\Tests\Support\BuildsForumContent;
use Tests\Support\Modules\ModuleTestCase;
use Tests\Support\Time\UsesUtcStorage;

/**
 * The editor of one's own post starts from its document with each reference labelled with the
 * number its post has now («#?» once it is gone); the label is only for the editor.
 */
final class PostReferenceLabelsTest extends ModuleTestCase
{
    use BuildsForumContent;
    use UsesUtcStorage;

    private const string MISSING = '0b6f1d7e-3c1a-4f0e-9a51-6f1e2d3c4b5a';

    private const string LABEL = 'posts.2.document.content.0.content.0.attrs.label';

    protected ?string $moduleName = 'forum';

    protected bool $autoEnableModule = true;

    private UserModel $author;

    private Discussion $discussion;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useUtcStorage();
        $this->author = $this->anAccount();
        [$this->discussion] = $this->aDiscussion($this->aCategory());
    }

    public function test_a_reference_is_labelled_with_its_post_number(): void
    {
        $target = $this->aReply($this->discussion, '2026-11-10 19:00:00');
        $this->authorsReplyPointingAt($target->id()->value);

        $this->actingAs($this->author)->get($this->discussionUrl())
            ->assertInertia(fn (AssertableInertia $page) => $page->where(self::LABEL, '#2')->etc());
    }

    public function test_a_reference_to_a_post_moved_away_gets_its_new_number(): void
    {
        $this->aReply($this->discussion, '2026-11-10 18:30:00');
        $target = $this->aReply($this->discussion, '2026-11-10 19:00:00');
        $this->authorsReplyPointingAt($target->id()->value);
        app(ForumWriter::class)->splitDiscussion($this->discussion->id(), [(int) $target->number()?->value], $this->discussion->categoryId(), new DiscussionTitle('Aparte'), new DateTimeImmutable('2026-11-10 22:00:00'));
        $moved = app(PostRepositoryInterface::class)->findOrFail($target->id());

        // The author's reply is now post 3 of this discussion
        $this->actingAs($this->author)->get($this->discussionUrl())
            ->assertInertia(fn (AssertableInertia $page) => $page->where('posts.2.document.content.0.content.0.attrs.label', '#'.$moved->number()?->value)->etc());
    }

    public function test_a_reference_to_a_post_that_is_gone_is_unknown(): void
    {
        $this->aReply($this->discussion, '2026-11-10 19:00:00');
        $this->authorsReplyPointingAt(self::MISSING);

        $this->actingAs($this->author)->get($this->discussionUrl())
            ->assertInertia(fn (AssertableInertia $page) => $page->where(self::LABEL, '#?')->etc());
    }

    public function test_posts_the_reader_cannot_edit_carry_no_document(): void
    {
        $target = $this->aReply($this->discussion, '2026-11-10 19:00:00');
        $this->authorsReplyPointingAt($target->id()->value);

        $this->actingAs($this->anAccount())->get($this->discussionUrl())
            ->assertInertia(fn (AssertableInertia $page) => $page->where('posts.2.document', null)->etc());
    }

    private function authorsReplyPointingAt(string $postId): Post
    {
        $body = new PostBody(['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [
            ['type' => 'postReference', 'attrs' => ['postId' => $postId]],
            ['type' => 'text', 'text' => ' eso'],
        ]]]], ' eso');

        return app(ForumWriter::class)->reply($this->discussion->id(), PostId::generate(), $this->author->id, $body, new DateTimeImmutable('2026-11-10 20:00:00'), false);
    }

    private function discussionUrl(): string
    {
        $discussion = app(DiscussionRepositoryInterface::class)->find($this->discussion->id());
        $this->assertInstanceOf(Discussion::class, $discussion);

        return '/foro/t/'.$discussion->number().'-'.str($discussion->title()->value)->slug();
    }
}
