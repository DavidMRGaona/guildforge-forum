<?php

declare(strict_types=1);

namespace Modules\Forum\Tests\Feature\Http;

use App\Application\Services\UserServiceInterface;
use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use DateTimeImmutable;
use Inertia\Testing\AssertableInertia;
use Modules\Forum\Application\Contracts\SystemAccountInterface;
use Modules\Forum\Application\Conversations\BlockService;
use Modules\Forum\Application\Services\ForumWriter;
use Modules\Forum\Application\Services\SuspensionService;
use Modules\Forum\Domain\Entities\Discussion;
use Modules\Forum\Domain\ValueObjects\CategoryAudience;
use Modules\Forum\Domain\ValueObjects\PostId;
use Modules\Forum\Tests\Support\BuildsForumContent;
use Tests\Support\Modules\ModuleTestCase;
use Tests\Support\Time\UsesUtcStorage;

/**
 * «Escribir un mensaje privado» next to the author of a post (forum#88): only where the reader
 * could start a conversation with that person. Anything that would refuse it (no session, a
 * suspension, an account that takes no messages, a block) leaves the action out, all alike, so
 * a block is never told apart.
 */
final class PrivateMessageLinksTest extends ModuleTestCase
{
    use BuildsForumContent;
    use UsesUtcStorage;

    private const string URL = '/foro/t/1-sesion-del-viernes';

    protected ?string $moduleName = 'forum';

    protected bool $autoEnableModule = true;

    private UserModel $author;

    private UserModel $reader;

    private Discussion $discussion;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useUtcStorage();
        $category = $this->aCategoryFor(CategoryAudience::everyone(), 'general');
        $this->author = $this->anAccount();
        $this->reader = $this->anAccount();
        [$this->discussion] = $this->aDiscussion($category, authorId: strtolower($this->author->id));
    }

    public function test_a_reader_who_may_write_to_the_author_gets_the_link(): void
    {
        $this->actingAs($this->reader)->get(self::URL)
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Forum/Discussion', false)
                ->where('posts.0.messageUrl', $this->expectedUrl()));
    }

    public function test_guests_and_the_author_get_none(): void
    {
        $this->get(self::URL)->assertInertia(fn (AssertableInertia $page) => $page->where('posts.0.messageUrl', null));
        $this->actingAs($this->author)->get(self::URL)->assertInertia(fn (AssertableInertia $page) => $page->where('posts.0.messageUrl', null));
    }

    public function test_a_suspended_reader_gets_none(): void
    {
        app(SuspensionService::class)->suspend(UserModel::factory()->admin()->create(), $this->reader->id, 'Spam', null);

        $this->assertNoLinkFor($this->reader);
    }

    public function test_an_author_who_takes_no_messages_gets_no_link(): void
    {
        app(SuspensionService::class)->suspend(UserModel::factory()->admin()->create(), $this->author->id, 'Spam', null);
        $this->assertNoLinkFor($this->reader);
    }

    public function test_a_deactivated_or_anonymized_author_gets_no_link(): void
    {
        $this->author->delete();
        $this->assertNoLinkFor($this->reader);

        $this->author->restore();
        app(UserServiceInterface::class)->anonymize($this->author->id);
        $this->assertNoLinkFor($this->anAccount());
    }

    public function test_an_author_who_blocked_the_reader_gets_no_link_and_the_one_who_blocked_still_does(): void
    {
        app(BlockService::class)->block($this->author, $this->reader->id);

        $this->assertNoLinkFor($this->reader);
        // Blocking is one way: whoever blocked may still write to the person they blocked
        $reply = app(ForumWriter::class)->reply($this->discussion->id(), PostId::generate(), strtolower($this->reader->id), self::aBody('Hola'), new DateTimeImmutable('2026-11-10 18:05:00'), false);
        $this->actingAs($this->author)->get(self::URL)
            ->assertInertia(fn (AssertableInertia $page) => $page->where('posts.1.id', $reply->id()->value)
                ->where('posts.1.messageUrl', route('forum.conversations.create', ['para' => strtolower($this->reader->id)])));
    }

    public function test_posts_without_a_person_shown_get_no_link(): void
    {
        $system = app(SystemAccountInterface::class)->id();
        app(ForumWriter::class)->reply($this->discussion->id(), PostId::generate(), $system, self::aBody('Aviso'), new DateTimeImmutable('2026-11-10 18:05:00'), false);
        $this->aReply($this->discussion, '2026-11-10 18:06:00');

        $this->actingAs($this->reader)->get(self::URL)
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('posts.1.messageUrl', null)
                ->where('posts.2.messageUrl', null));
    }

    public function test_posts_arriving_while_the_page_is_open_carry_it_too(): void
    {
        app(ForumWriter::class)->reply($this->discussion->id(), PostId::generate(), strtolower($this->author->id), self::aBody('Otra'), new DateTimeImmutable('2026-11-10 18:05:00'), false);

        $this->actingAs($this->reader)->getJson(route('forum.discussions.updates', ['discussion' => $this->discussion->id()->value, 'despues' => 1]))
            ->assertOk()
            ->assertJsonPath('posts.0.messageUrl', $this->expectedUrl());
    }

    private function assertNoLinkFor(UserModel $reader): void
    {
        app()->forgetScopedInstances();
        $this->actingAs($reader)->get(self::URL)
            ->assertInertia(fn (AssertableInertia $page) => $page->where('posts.0.messageUrl', null));
    }

    private function expectedUrl(): string
    {
        return route('forum.conversations.create', ['para' => strtolower($this->author->id)]);
    }
}
