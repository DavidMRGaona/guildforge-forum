<?php

declare(strict_types=1);

namespace Modules\Forum\Tests\Feature\Http;

use App\Infrastructure\Persistence\Eloquent\Models\RoleModel;
use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use DateTimeImmutable;
use Modules\Forum\Application\Services\ForumWriter;
use Modules\Forum\Application\Services\PostBodyFactory;
use Modules\Forum\Domain\Entities\Discussion;
use Modules\Forum\Domain\Entities\Post;
use Modules\Forum\Domain\Repositories\PostRepositoryInterface;
use Modules\Forum\Domain\ValueObjects\CategoryAudience;
use Modules\Forum\Domain\ValueObjects\PostBody;
use Modules\Forum\Domain\ValueObjects\PostId;
use Modules\Forum\Tests\Support\BuildsForumContent;
use Tests\Support\Modules\ModuleTestCase;
use Tests\Support\Time\UsesUtcStorage;

/**
 * Replying or editing turns «#N» into a reference to post N of the discussion, with the access
 * of whoever writes. A reference that comes already made from the editor must point at a post
 * they may read, unless the post already had it.
 */
final class PostReferenceWritingTest extends ModuleTestCase
{
    use BuildsForumContent;
    use UsesUtcStorage;

    protected ?string $moduleName = 'forum';

    protected bool $autoEnableModule = true;

    private UserModel $author;

    private Discussion $discussion;

    private Post $first;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useUtcStorage();
        $this->author = $this->anAccount();
        [$this->discussion, $this->first] = $this->aDiscussion($this->aCategory());
    }

    public function test_a_reply_turns_a_number_into_a_reference(): void
    {
        $response = $this->actingAs($this->author)->postJson($this->replyUrl(), ['document' => $this->doc([$this->text('Ver #1')])])
            ->assertCreated();

        $this->assertSame($this->doc([$this->text('Ver '), $this->reference($this->first->id()->value)]), $this->bodyOf((string) $response->json('post.id')));
    }

    public function test_an_edit_keeps_its_references_and_converts_new_numbers(): void
    {
        $second = $this->aReply($this->discussion, '2026-11-10 19:00:00');
        $mine = $this->replyOfAuthor($this->doc([$this->text('Ver '), $this->reference($this->first->id()->value)]));

        $this->actingAs($this->author)->patchJson($this->updateUrl($mine), ['document' => $this->doc([
            $this->text('Ver '),
            ['type' => 'postReference', 'attrs' => ['postId' => $this->first->id()->value, 'label' => '#1']],
            $this->text(' y #2'),
        ])])->assertOk();

        $this->assertSame(
            $this->doc([$this->text('Ver '), $this->reference($this->first->id()->value), $this->text(' y '), $this->reference($second->id()->value)]),
            $this->bodyOf($mine->id()->value),
        );
    }

    public function test_a_made_reference_to_a_hidden_post_is_refused(): void
    {
        $hidden = $this->aReply($this->discussion, '2026-11-10 19:00:00');
        app(ForumWriter::class)->changePost($hidden->id(), static fn (Post $post) => $post->hide());

        $this->actingAs($this->author)->postJson($this->replyUrl(), ['document' => $this->doc([$this->reference($hidden->id()->value)])])
            ->assertUnprocessable()
            ->assertJsonPath('reason', 'unknown_post');
    }

    public function test_a_made_reference_to_a_post_the_author_cannot_read_is_refused(): void
    {
        $role = RoleModel::query()->create(['name' => 'keepers', 'display_name' => 'Keepers']);
        [, $private] = $this->aDiscussion($this->aCategoryFor(CategoryAudience::roles([$role->id]), 'privada'));

        $this->actingAs($this->author)->postJson($this->replyUrl(), ['document' => $this->doc([$this->reference($private->id()->value)])])
            ->assertUnprocessable()
            ->assertJsonPath('reason', 'unknown_post');
    }

    public function test_a_number_of_a_hidden_post_stays_text(): void
    {
        $hidden = $this->aReply($this->discussion, '2026-11-10 19:00:00');
        app(ForumWriter::class)->changePost($hidden->id(), static fn (Post $post) => $post->hide());

        $response = $this->actingAs($this->author)->postJson($this->replyUrl(), ['document' => $this->doc([$this->text('Ver #2')])])
            ->assertCreated();

        $this->assertSame($this->doc([$this->text('Ver #2')]), $this->bodyOf((string) $response->json('post.id')));
    }

    public function test_an_edit_keeps_a_reference_to_a_post_hidden_since(): void
    {
        $target = $this->aReply($this->discussion, '2026-11-10 19:00:00');
        $mine = $this->replyOfAuthor($this->doc([$this->text('Ver '), $this->reference($target->id()->value)]));
        app(ForumWriter::class)->changePost($target->id(), static fn (Post $post) => $post->hide());

        $this->actingAs($this->author)->patchJson($this->updateUrl($mine), ['document' => $this->doc([
            $this->text('Ya no, '),
            ['type' => 'postReference', 'attrs' => ['postId' => $target->id()->value, 'label' => '#2']],
        ])])->assertOk();

        $this->assertSame($this->doc([$this->text('Ya no, '), $this->reference($target->id()->value)]), $this->bodyOf($mine->id()->value));
    }

    public function test_a_moderator_editing_someone_elses_post_converts_numbers(): void
    {
        $mine = $this->replyOfAuthor($this->doc([$this->text('Hola')]));

        $this->actingAs($this->anAccount('forum:forum.moderate'))
            ->patchJson($this->updateUrl($mine), ['document' => $this->doc([$this->text('Hola #1')])])
            ->assertOk();

        $this->assertSame($this->doc([$this->text('Hola '), $this->reference($this->first->id()->value)]), $this->bodyOf($mine->id()->value));
    }

    public function test_editing_a_post_of_a_discussion_one_cannot_read_tells_nothing_about_its_posts(): void
    {
        $role = RoleModel::query()->create(['name' => 'keepers', 'display_name' => 'Keepers']);
        [$private, $privateFirst] = $this->aDiscussion($this->aCategoryFor(CategoryAudience::roles([$role->id]), 'privada'));
        $this->aReply($private, '2026-11-10 19:00:00');

        // With a number of a post that exists there and without one: the same answer
        foreach (['Hola #2', 'Hola'] as $text) {
            $this->actingAs($this->author)->patchJson($this->updateUrl($privateFirst), ['document' => self::doc([self::text($text)])])
                ->assertNotFound();
        }
    }

    public function test_without_a_discussion_numbers_stay_text(): void
    {
        // A new discussion's first post has nothing to point at yet (DiscussionStarter gives no discussion)
        $body = app(PostBodyFactory::class)->fromInput($this->doc([$this->text('Como en #1')]), $this->author);

        $this->assertSame($this->doc([$this->text('Como en #1')]), $body->document);
    }

    /**
     * @param  array<string, mixed>  $document
     */
    private function replyOfAuthor(array $document): Post
    {
        return app(ForumWriter::class)->reply($this->discussion->id(), PostId::generate(), $this->author->id, new PostBody($document, 'texto'), new DateTimeImmutable('2026-11-10 20:00:00'), false);
    }

    /**
     * @return array<string, mixed>
     */
    private function bodyOf(string $postId): array
    {
        return app(PostRepositoryInterface::class)->findOrFail(PostId::fromString($postId))->body()->document;
    }

    private function replyUrl(): string
    {
        return route('forum.posts.store', ['discussion' => $this->discussion->id()->value]);
    }

    private function updateUrl(Post $post): string
    {
        return route('forum.posts.update', ['post' => $post->id()->value]);
    }

    /**
     * @param  list<array<string, mixed>>  $inline
     * @return array<string, mixed>
     */
    private function doc(array $inline): array
    {
        return ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => $inline]]];
    }

    /**
     * @return array<string, mixed>
     */
    private function text(string $text): array
    {
        return ['type' => 'text', 'text' => $text];
    }

    /**
     * @return array<string, mixed>
     */
    private function reference(string $postId): array
    {
        return ['type' => 'postReference', 'attrs' => ['postId' => $postId]];
    }
}
