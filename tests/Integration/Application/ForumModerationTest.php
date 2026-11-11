<?php

declare(strict_types=1);

namespace Modules\Forum\Tests\Integration\Application;

use App\Domain\Forum\Events\ForumDiscussionStarted;
use App\Application\Authorization\Services\AuthorizationServiceInterface;
use App\Infrastructure\Persistence\Eloquent\Models\RoleModel;
use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use Illuminate\Support\Facades\Event;
use Modules\Forum\Application\Contracts\ForumCountersInterface;
use Modules\Forum\Application\Services\ForumModeration;
use Modules\Forum\Domain\Entities\Category;
use Modules\Forum\Domain\Entities\Discussion;
use Modules\Forum\Domain\Enums\DiscussionStatus;
use Modules\Forum\Domain\Enums\ModerationAction;
use Modules\Forum\Domain\Enums\PostStatus;
use Modules\Forum\Domain\Enums\SystemCategory;
use Modules\Forum\Domain\Exceptions\DiscussionNotFoundException;
use Modules\Forum\Domain\Exceptions\ForumActionDeniedException;
use Modules\Forum\Domain\Exceptions\InvalidDiscussionException;
use Modules\Forum\Domain\Exceptions\InvalidPostException;
use Modules\Forum\Domain\Exceptions\ModerationRefusedException;
use Modules\Forum\Domain\Exceptions\PostNotFoundException;
use Modules\Forum\Domain\Repositories\CategoryRepositoryInterface;
use Modules\Forum\Domain\Repositories\DiscussionRepositoryInterface;
use Modules\Forum\Domain\Repositories\PostRepositoryInterface;
use Modules\Forum\Domain\Repositories\PostRevisionRepositoryInterface;
use Modules\Forum\Domain\Repositories\SubjectDiscussionRepositoryInterface;
use Modules\Forum\Domain\ValueObjects\CategoryAudience;
use Modules\Forum\Domain\ValueObjects\CategoryId;
use Modules\Forum\Domain\ValueObjects\CategoryModerators;
use Modules\Forum\Domain\ValueObjects\CategorySlug;
use Modules\Forum\Domain\ValueObjects\PostId;
use Modules\Forum\Domain\ValueObjects\PostNumber;
use Modules\Forum\Domain\ValueObjects\PublicationKey;
use Modules\Forum\Infrastructure\Persistence\Eloquent\Models\ModerationLogModel;
use Modules\Forum\Tests\Support\BuildsForumContent;
use Tests\Support\Modules\ModuleTestCase;
use Tests\Support\Time\UsesUtcStorage;

/**
 * Moderation in a discussion: only its moderators act, each action is logged with who did it,
 * and what moderation does is marked as theirs.
 */
final class ForumModerationTest extends ModuleTestCase
{
    use BuildsForumContent;
    use UsesUtcStorage;

    private const string MODERATE = 'forum:forum.moderate';

    protected ?string $moduleName = 'forum';

    protected bool $autoEnableModule = true;

    private Category $general;

    private UserModel $author;

    private UserModel $moderator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useUtcStorage();
        $this->general = $this->aCategoryFor(CategoryAudience::everyone(), 'general');
        $this->author = $this->anAccount();
        $this->moderator = $this->anAccount(self::MODERATE);
    }

    public function test_someone_who_does_not_moderate_is_refused_and_logs_nothing(): void
    {
        $discussion = $this->started();

        try {
            $this->moderation()->pin($this->anAccount(), $discussion->id());
            $this->fail('A reader pinned a discussion.');
        } catch (ForumActionDeniedException) {
            $this->assertFalse($this->stored($discussion)->isPinned());
            $this->assertSame(0, ModerationLogModel::query()->count());
        }
    }

    public function test_what_the_person_cannot_read_is_not_found(): void
    {
        $discussion = $this->started();
        $this->moderation()->hide($this->moderator, $discussion->id());

        $this->expectException(DiscussionNotFoundException::class);

        $this->moderation()->close($this->anAccount(), $discussion->id());
    }

    public function test_pinning_closing_and_hiding_are_logged_with_who_and_why(): void
    {
        $discussion = $this->started();

        $this->moderation()->pin($this->moderator, $discussion->id());
        $this->moderation()->close($this->moderator, $discussion->id(), 'Ya está resuelto');
        $this->moderation()->hide($this->moderator, $discussion->id());

        $stored = $this->stored($discussion);
        $this->assertTrue($stored->isPinned());
        $this->assertSame(DiscussionStatus::Closed, $stored->status());
        // Closed by moderation: the forum never reopens it
        $this->assertFalse($stored->isClosedBySystem());
        $this->assertTrue($stored->isHidden());

        $this->assertSame(
            [ModerationAction::DiscussionPinned->value, ModerationAction::DiscussionClosed->value, ModerationAction::DiscussionHidden->value],
            ModerationLogModel::query()->orderBy('created_at')->orderBy('id')->pluck('action')->all(),
        );

        $closed = ModerationLogModel::query()->where('action', ModerationAction::DiscussionClosed->value)->sole();
        $this->assertSame(strtolower($this->moderator->id), $closed->moderator_id);
        $this->assertSame(strtolower($this->author->id), $closed->subject_user_id);
        $this->assertSame($discussion->id()->value, $closed->discussion_id);
        $this->assertSame('Ya está resuelto', $closed->reason);
        $this->assertSame('Sesión del viernes', $closed->details['discussion_title'] ?? null);

        // A hidden discussion leaves the counters
        $this->assertSame(0, app(ForumCountersInterface::class)->category($this->general->id())?->discussionCount);
    }

    public function test_closing_twice_is_a_state_error(): void
    {
        $discussion = $this->started();
        $this->moderation()->close($this->moderator, $discussion->id());

        $this->expectException(InvalidDiscussionException::class);

        $this->moderation()->close($this->moderator, $discussion->id());
    }

    public function test_the_moderators_of_a_category_moderate_it_and_nothing_else(): void
    {
        $leader = $this->anAccount();
        $team = $this->aCategoryFor(CategoryAudience::everyone(), 'equipo');
        $team->changeAccess(CategoryAudience::everyone(), CategoryAudience::signedIn(), new CategoryModerators([$this->roleOf($leader)], null));
        app(CategoryRepositoryInterface::class)->save($team);

        $this->moderation()->pin($leader, $this->started($team)->id());

        $this->expectException(ForumActionDeniedException::class);
        $this->moderation()->pin($leader, $this->started()->id());
    }

    public function test_renaming_makes_the_title_moderations(): void
    {
        $discussion = $this->started();

        $this->moderation()->rename($this->moderator, $discussion->id(), '  Nuevo   título ');

        $stored = $this->stored($discussion);
        $this->assertSame('Nuevo título', $stored->title()->value);
        $this->assertTrue($stored->isTitleSetByModeration());

        $entry = ModerationLogModel::query()->sole();
        $this->assertSame(['Sesión del viernes', 'Nuevo título'], [$entry->details['from'] ?? null, $entry->details['to'] ?? null]);
    }

    public function test_a_title_out_of_bounds_is_refused(): void
    {
        $this->expectException(ModerationRefusedException::class);

        $this->moderation()->rename($this->moderator, $this->started()->id(), 'ab');
    }

    public function test_moving_refreshes_both_categories_and_logs_where_from_and_to(): void
    {
        $discussion = $this->started();
        $other = $this->aCategoryFor(CategoryAudience::everyone(), 'otra');

        $this->moderation()->move($this->moderator, $discussion->id(), $other->id(), 'Va mejor aquí');

        $this->assertTrue($this->stored($discussion)->categoryId()->equals($other->id()));
        $counters = app(ForumCountersInterface::class);
        $this->assertSame(0, $counters->category($this->general->id())?->discussionCount);
        $this->assertSame(1, $counters->category($other->id())?->discussionCount);

        $entry = ModerationLogModel::query()->sole();
        $this->assertSame(['General', 'Otra'], [$entry->details['from'] ?? null, $entry->details['to'] ?? null]);
        $this->assertSame('Va mejor aquí', $entry->reason);
    }

    public function test_nothing_moves_into_the_categories_publications_fill_nor_where_it_is(): void
    {
        $discussion = $this->started();
        $tables = Category::system(CategoryId::generate(), SystemCategory::GameTables, 'Mesas', new CategorySlug('mesas'), 5);
        app(CategoryRepositoryInterface::class)->save($tables);

        foreach ([$tables->id(), $this->general->id()] as $target) {
            try {
                $this->moderation()->move($this->moderator, $discussion->id(), $target);
                $this->fail('The discussion was moved where it may not go.');
            } catch (ModerationRefusedException) {
                $this->assertTrue($this->stored($discussion)->categoryId()->equals($this->general->id()));
            }
        }

        $this->assertNotContains($tables->id()->value, array_map(
            static fn (Category $category): string => $category->id()->value,
            $this->moderation()->moveTargets($this->moderator, $discussion),
        ));
    }

    public function test_a_category_moderator_moves_only_into_categories_they_moderate(): void
    {
        $leader = $this->anAccount();
        $team = $this->aCategoryFor(CategoryAudience::everyone(), 'equipo');
        $team->changeAccess(CategoryAudience::everyone(), CategoryAudience::signedIn(), new CategoryModerators([$this->roleOf($leader)], null));
        app(CategoryRepositoryInterface::class)->save($team);
        $discussion = $this->started($team);

        $this->assertSame([], $this->moderation()->moveTargets($leader, $discussion));

        $this->expectException(ModerationRefusedException::class);
        $this->moderation()->move($leader, $discussion->id(), $this->general->id());
    }

    public function test_a_publications_discussion_stays_where_the_forum_keeps_it(): void
    {
        $discussion = $this->started();
        $record = app(SubjectDiscussionRepositoryInterface::class)->lockOrCreate(new PublicationKey('game-table', '0d2f3c4b-1a2b-4c3d-8e9f-0a1b2c3d4e5f'));
        $record->announce($discussion->id(), PostId::generate(), [], true);
        app(SubjectDiscussionRepositoryInterface::class)->save($record);

        $this->assertSame([], $this->moderation()->moveTargets($this->moderator, $discussion));

        $this->expectException(ModerationRefusedException::class);
        $this->moderation()->move($this->moderator, $discussion->id(), $this->aCategoryFor(CategoryAudience::everyone(), 'otra')->id());
    }

    public function test_only_whoever_moderates_the_whole_forum_deletes_and_the_log_keeps_its_title(): void
    {
        $leader = $this->anAccount();
        $team = $this->aCategoryFor(CategoryAudience::everyone(), 'equipo');
        $team->changeAccess(CategoryAudience::everyone(), CategoryAudience::signedIn(), new CategoryModerators([$this->roleOf($leader)], null));
        app(CategoryRepositoryInterface::class)->save($team);
        $discussion = $this->started($team);
        $this->aReply($discussion, '2026-11-10 18:30:00');

        $this->assertFalse($this->moderation()->canDelete($leader, $discussion));
        try {
            $this->moderation()->delete($leader, $discussion->id());
            $this->fail('A category moderator deleted a discussion.');
        } catch (ForumActionDeniedException) {
            $this->assertInstanceOf(Discussion::class, app(DiscussionRepositoryInterface::class)->find($discussion->id()));
        }

        $this->moderation()->delete($this->moderator, $discussion->id(), 'Spam');

        $this->assertNull(app(DiscussionRepositoryInterface::class)->find($discussion->id()));
        $entry = ModerationLogModel::query()->sole();
        $this->assertSame(ModerationAction::DiscussionDeleted->value, $entry->action);
        $this->assertSame('Sesión del viernes', $entry->details['discussion_title'] ?? null);
        $this->assertSame(2, $entry->details['posts'] ?? null);
    }

    public function test_hiding_a_post_keeps_its_reason_until_it_is_shown(): void
    {
        $discussion = $this->started();
        $reply = $this->aReply($discussion, '2026-11-10 18:30:00');

        $hidden = $this->moderation()->hidePost($this->moderator, $reply->id(), 'Fuera de tono');

        $this->assertSame(PostStatus::Hidden, $hidden->status());
        $this->assertSame('Fuera de tono', app(PostRepositoryInterface::class)->findOrFail($reply->id())->hiddenReason());
        $this->assertSame(1, app(ForumCountersInterface::class)->discussion($discussion->id())?->postCount);

        $this->moderation()->showPost($this->moderator, $reply->id());

        $shown = app(PostRepositoryInterface::class)->findOrFail($reply->id());
        $this->assertSame(PostStatus::Visible, $shown->status());
        $this->assertNull($shown->hiddenReason());
        $this->assertSame([ModerationAction::PostHidden->value, ModerationAction::PostShown->value], ModerationLogModel::query()->orderBy('id')->pluck('action')->all());
        $this->assertSame($reply->id()->value, ModerationLogModel::query()->firstOrFail()->post_id);
    }

    public function test_a_reason_too_long_is_refused_before_anything_changes(): void
    {
        $reply = $this->aReply($this->started(), '2026-11-10 18:30:00');

        $this->expectException(ModerationRefusedException::class);

        $this->moderation()->hidePost($this->moderator, $reply->id(), str_repeat('a', 301));
    }

    public function test_a_post_of_a_discussion_the_person_cannot_read_is_not_found(): void
    {
        $reply = $this->aReply($this->started(), '2026-11-10 18:30:00');
        $this->moderation()->hide($this->moderator, $reply->discussionId());

        $this->expectException(PostNotFoundException::class);

        $this->moderation()->hidePost($this->anAccount(), $reply->id());
    }

    public function test_editing_someone_elses_post_keeps_the_version_and_logs_it(): void
    {
        $discussion = $this->started();
        $first = app(PostRepositoryInterface::class)->numberedFrom($discussion->id(), new PostNumber(1), 1)[0];

        $this->moderation()->editPost($this->moderator, $first->id(), self::aBody('Texto moderado'));
        // The same document again changes nothing
        $this->moderation()->editPost($this->moderator, $first->id(), self::aBody('Texto moderado'));

        $this->assertSame('Texto moderado', app(PostRepositoryInterface::class)->findOrFail($first->id())->body()->plainText);
        $revisions = app(PostRevisionRepositoryInterface::class)->ofPost($first->id());
        $this->assertCount(1, $revisions);
        $this->assertSame(strtolower($this->moderator->id), $revisions[0]->editorId());
        $this->assertSame(ModerationAction::PostEdited->value, ModerationLogModel::query()->sole()->action);
    }

    public function test_approving_a_reply_gives_it_the_next_number(): void
    {
        $discussion = $this->started();
        $pending = $this->aReply($discussion, '2026-11-10 18:30:00', needsApproval: true);
        $this->aReply($discussion, '2026-11-10 18:40:00');

        $approved = $this->moderation()->approve($this->moderator, $pending->id());

        $this->assertSame(3, $approved->number()?->value);
        $this->assertSame(ModerationAction::PostApproved->value, ModerationLogModel::query()->sole()->action);

        $this->expectException(InvalidPostException::class);
        $this->moderation()->approve($this->moderator, $pending->id());
    }

    public function test_rejecting_a_reply_takes_it_away(): void
    {
        $discussion = $this->started();
        $pending = $this->aReply($discussion, '2026-11-10 18:30:00', needsApproval: true);

        $this->moderation()->reject($this->moderator, $pending->id(), 'Publicidad');

        $this->assertNull(app(PostRepositoryInterface::class)->find($pending->id()));
        $this->assertInstanceOf(Discussion::class, app(DiscussionRepositoryInterface::class)->find($discussion->id()));
        $entry = ModerationLogModel::query()->sole();
        $this->assertSame([ModerationAction::PostRejected->value, 'Publicidad', false], [$entry->action, $entry->reason, $entry->details['whole_discussion'] ?? null]);
    }

    public function test_a_waiting_discussion_opens_and_is_announced_once_its_first_post_is_approved(): void
    {
        Event::fake([ForumDiscussionStarted::class]);
        config(['modules.settings.forum.announced_categories' => [$this->general->id()->value]]);
        [$discussion, $first] = $this->aDiscussion($this->general, authorId: strtolower($this->author->id), needsApproval: true);

        $this->assertTrue($this->stored($discussion)->isAwaitingApproval());
        // Until then, only its moderators act on it: approve or reject
        try {
            $this->moderation()->pin($this->moderator, $discussion->id());
            $this->fail('A waiting discussion was pinned.');
        } catch (ModerationRefusedException) {
        }

        $approved = $this->moderation()->approve($this->moderator, $first->id());

        $this->assertSame(1, $approved->number()?->value);
        $this->assertFalse($this->stored($discussion)->isAwaitingApproval());
        $this->assertSame(1, app(ForumCountersInterface::class)->category($this->general->id())?->discussionCount);
        Event::assertDispatchedTimes(ForumDiscussionStarted::class, 1);
    }

    public function test_a_waiting_discussion_outside_the_announced_categories_is_not_announced(): void
    {
        Event::fake([ForumDiscussionStarted::class]);
        [, $first] = $this->aDiscussion($this->general, authorId: strtolower($this->author->id), needsApproval: true);

        $this->moderation()->approve($this->moderator, $first->id());

        Event::assertNotDispatched(ForumDiscussionStarted::class);
    }

    public function test_rejecting_the_first_post_of_a_waiting_discussion_takes_the_discussion_away(): void
    {
        [$discussion, $first] = $this->aDiscussion($this->general, authorId: strtolower($this->author->id), needsApproval: true);

        $this->moderation()->reject($this->moderator, $first->id());

        $this->assertNull(app(DiscussionRepositoryInterface::class)->find($discussion->id()));
        $this->assertTrue(ModerationLogModel::query()->sole()->details['whole_discussion'] ?? false);
    }

    private function started(?Category $category = null): Discussion
    {
        [$discussion] = $this->aDiscussion($category ?? $this->general, authorId: strtolower($this->author->id));

        return $discussion;
    }

    private function stored(Discussion $discussion): Discussion
    {
        return app(DiscussionRepositoryInterface::class)->findOrFail($discussion->id());
    }

    /**
     * The id of a role of the person's own, given to them now.
     */
    private function roleOf(UserModel $user): string
    {
        $role = RoleModel::query()->create(['name' => 'role-'.strtolower(uniqid()), 'display_name' => 'Role']);
        $user->roles()->attach($role->id);
        app(AuthorizationServiceInterface::class)->invalidateCacheFor($user);

        return strtolower((string) $role->id);
    }

    private function moderation(): ForumModeration
    {
        return app(ForumModeration::class);
    }
}
