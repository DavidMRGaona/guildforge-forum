<?php

declare(strict_types=1);

namespace Modules\Forum\Tests\Integration\Application;

use App\Application\Mail\Services\MailSubjectMuteServiceInterface;
use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use Modules\Forum\Application\Contracts\FollowsInterface;
use Modules\Forum\Application\Contracts\ForumAccessInterface;
use Modules\Forum\Application\Contracts\SystemAccountInterface;
use Modules\Forum\Application\Services\FollowService;
use Modules\Forum\Application\Services\ForumMailSubjects;
use Modules\Forum\Domain\Entities\Discussion;
use Modules\Forum\Domain\Enums\FollowLevel;
use Modules\Forum\Domain\Exceptions\CategoryNotFoundException;
use Modules\Forum\Domain\Exceptions\DiscussionNotFoundException;
use Modules\Forum\Domain\ValueObjects\CategoryAudience;
use Modules\Forum\Tests\Support\BuildsForumContent;
use Modules\Forum\Tests\Support\FakeForumAccess;
use Tests\Support\Modules\ModuleTestCase;
use Tests\Support\Time\UsesUtcStorage;

/**
 * «Seguir», «Normal» and «Silenciar» for discussions, following after posting and following
 * categories. Muting is the core's mute of the discussion.
 */
final class FollowServiceTest extends ModuleTestCase
{
    use BuildsForumContent;
    use UsesUtcStorage;

    protected ?string $moduleName = 'forum';

    protected bool $autoEnableModule = true;

    private FakeForumAccess $access;

    private UserModel $reader;

    private Discussion $discussion;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useUtcStorage();
        $this->access = new FakeForumAccess;
        $this->app->instance(ForumAccessInterface::class, $this->access);

        $this->reader = UserModel::factory()->create();
        $this->access->letRead($this->reader);
        [$this->discussion] = $this->aDiscussion($this->aCategory());
    }

    public function test_a_discussion_nobody_chose_anything_for_is_normal(): void
    {
        $this->assertSame(FollowLevel::Normal, $this->service()->levelOf($this->reader, $this->discussion));
        $this->assertSame(FollowLevel::Normal, $this->service()->levelOf(null, $this->discussion));
    }

    public function test_following_and_back_to_normal(): void
    {
        $this->service()->setLevel($this->reader, $this->discussion->id(), FollowLevel::Following);
        $this->assertSame(FollowLevel::Following, $this->service()->levelOf($this->reader, $this->discussion));
        $this->assertSame([strtolower($this->reader->id)], app(FollowsInterface::class)->discussionFollowers($this->discussion->id()));

        $this->service()->setLevel($this->reader, $this->discussion->id(), FollowLevel::Normal);
        $this->assertSame(FollowLevel::Normal, $this->service()->levelOf($this->reader, $this->discussion));
        $this->assertSame([], app(FollowsInterface::class)->discussionFollowers($this->discussion->id()));
    }

    public function test_muting_is_the_cores_mute_of_the_discussion_and_choosing_again_lifts_it(): void
    {
        $this->service()->setLevel($this->reader, $this->discussion->id(), FollowLevel::Following);
        $this->service()->setLevel($this->reader, $this->discussion->id(), FollowLevel::Muted);

        $subject = ForumMailSubjects::discussion($this->discussion);
        $this->assertTrue(app(MailSubjectMuteServiceInterface::class)->isMuted($this->reader, $subject));
        $this->assertSame(FollowLevel::Muted, $this->service()->levelOf($this->reader, $this->discussion));
        $this->assertSame([], app(FollowsInterface::class)->discussionFollowers($this->discussion->id()));

        $this->service()->setLevel($this->reader, $this->discussion->id(), FollowLevel::Following);
        $this->assertFalse(app(MailSubjectMuteServiceInterface::class)->isMuted($this->reader, $subject));
        $this->assertSame(FollowLevel::Following, $this->service()->levelOf($this->reader, $this->discussion));
    }

    public function test_muting_from_a_mail_footer_shows_as_muted_in_the_forum(): void
    {
        app(MailSubjectMuteServiceInterface::class)->mute($this->reader, ForumMailSubjects::discussion($this->discussion));

        $this->assertSame(FollowLevel::Muted, $this->service()->levelOf($this->reader, $this->discussion));
    }

    public function test_only_a_discussion_the_person_reads_can_be_followed(): void
    {
        $stranger = UserModel::factory()->create();

        $this->expectException(DiscussionNotFoundException::class);

        $this->service()->setLevel($stranger, $this->discussion->id(), FollowLevel::Following);
    }

    public function test_posting_follows_the_discussion_unless_the_person_chose_otherwise(): void
    {
        $author = UserModel::factory()->create();
        $this->service()->followAfterPosting(strtolower($author->id), $this->discussion->id());
        $this->assertContains(strtolower($author->id), app(FollowsInterface::class)->discussionFollowers($this->discussion->id()));

        // An explicit «Normal» (or a mute, which keeps «Normal») is not overridden by replying
        $this->service()->setLevel($this->reader, $this->discussion->id(), FollowLevel::Muted);
        $this->service()->followAfterPosting(strtolower($this->reader->id), $this->discussion->id());
        $this->assertNotContains(strtolower($this->reader->id), app(FollowsInterface::class)->discussionFollowers($this->discussion->id()));
    }

    public function test_posting_follows_nothing_once_the_person_turned_it_off_nor_for_the_system_account(): void
    {
        $author = UserModel::factory()->create();
        $this->service()->setAutoFollows($author, false);
        $this->assertFalse($this->service()->autoFollows($author));

        $this->service()->followAfterPosting(strtolower($author->id), $this->discussion->id());
        $this->service()->followAfterPosting(app(SystemAccountInterface::class)->id(), $this->discussion->id());

        $this->assertSame([], app(FollowsInterface::class)->discussionFollowers($this->discussion->id()));
    }

    public function test_a_category_is_followed_only_by_who_may_read_it(): void
    {
        $open = $this->aCategoryFor(CategoryAudience::signedIn(), 'abierta');
        $members = $this->aCategoryFor(CategoryAudience::associationMembers(), 'socios');

        $this->service()->setCategoryFollow($this->reader, $open, true);
        $this->assertTrue($this->service()->followsCategory($this->reader, $open));
        $this->assertSame([strtolower($this->reader->id)], app(FollowsInterface::class)->categoryFollowers([$open->id()]));

        $this->service()->setCategoryFollow($this->reader, $open, false);
        $this->assertFalse($this->service()->followsCategory($this->reader, $open));

        $this->expectException(CategoryNotFoundException::class);
        $this->service()->setCategoryFollow($this->reader, $members, true);
    }

    private function service(): FollowService
    {
        return app(FollowService::class);
    }
}
