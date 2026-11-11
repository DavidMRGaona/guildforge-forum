<?php

declare(strict_types=1);

namespace Modules\Forum\Tests\Integration\Content;

use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use DateTimeImmutable;
use Modules\Forum\Application\Contracts\ForumAccessInterface;
use Modules\Forum\Application\Services\ForumWriter;
use Modules\Forum\Application\Services\PostContentPresenter;
use Modules\Forum\Domain\Repositories\PostRepositoryInterface;
use Modules\Forum\Tests\Support\BuildsForumContent;
use Modules\Forum\Tests\Support\FakeForumAccess;
use Tests\Support\Modules\ModuleTestCase;
use Tests\Support\Time\UsesUtcStorage;

/**
 * Deleted and hidden posts keep their place with a placeholder; their moderators (and a hidden
 * post's author) still read them.
 */
final class PostContentPresenterTest extends ModuleTestCase
{
    use BuildsForumContent;
    use UsesUtcStorage;

    protected ?string $moduleName = 'forum';

    protected bool $autoEnableModule = true;

    public function test_each_reader_sees_what_they_may(): void
    {
        $this->useUtcStorage();
        $access = new FakeForumAccess;
        $access->guestsRead = true;
        $this->app->instance(ForumAccessInterface::class, $access);

        $author = UserModel::factory()->create();
        $moderator = UserModel::factory()->create();
        $access->letWrite($author)->letModerate($moderator);

        [$discussion, $visible] = $this->aDiscussion($this->aCategory(), authorId: $author->id);
        $hidden = $this->aReply($discussion, '2026-11-10 18:30:00');
        $hidden = app(PostRepositoryInterface::class)->findOrFail($hidden->id());
        $hidden->hide();
        app(ForumWriter::class)->savePost($hidden);
        $deleted = app(ForumWriter::class)->deletePostByAuthor($this->aReply($discussion, '2026-11-10 18:40:00', text: 'Me arrepiento')->id(), new DateTimeImmutable('2026-11-10 19:30:00'));
        $posts = app(PostRepositoryInterface::class)->inDiscussion($discussion->id());

        $presenter = app(PostContentPresenter::class);
        $forGuest = $presenter->present($posts, $discussion, null);
        $forAuthor = $presenter->present($posts, $discussion, $author);
        $forModerator = $presenter->present($posts, $discussion, $moderator);

        $this->assertSame('<p>Primer mensaje</p>', $forGuest[0]->html);
        $this->assertNull($forGuest[0]->placeholder);
        $this->assertFalse($forGuest[0]->canEdit);
        $this->assertNull($forGuest[1]->html);
        $this->assertSame('Mensaje oculto por la moderación', $forGuest[1]->placeholder);
        $this->assertNull($forGuest[2]->html);
        $this->assertSame('Mensaje borrado', $forGuest[2]->placeholder);
        $this->assertSame(3, $forGuest[2]->number);

        $this->assertTrue($forAuthor[0]->canEdit);
        $this->assertTrue($forAuthor[0]->canDelete);

        $this->assertSame('<p>Respuesta</p>', $forModerator[1]->html);
        $this->assertSame('<p>Me arrepiento</p>', $forModerator[2]->html);
        $this->assertSame('Mensaje borrado', $forModerator[2]->placeholder);
        $this->assertFalse($forModerator[2]->canEdit);

        // Once purged, moderators see only the placeholder too
        app(ForumWriter::class)->purgeDeletedPost($deleted->id(), new DateTimeImmutable('2026-12-15 04:10:00'));
        $posts = app(PostRepositoryInterface::class)->inDiscussion($discussion->id());
        $purged = $presenter->present($posts, $discussion, $moderator)[2];
        $this->assertNull($purged->html);
        $this->assertSame('Mensaje borrado', $purged->placeholder);
    }
}
