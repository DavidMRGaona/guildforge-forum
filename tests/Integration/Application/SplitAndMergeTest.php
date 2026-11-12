<?php

declare(strict_types=1);

namespace Modules\Forum\Tests\Integration\Application;

use App\Application\Authorization\Services\AuthorizationServiceInterface;
use App\Infrastructure\Persistence\Eloquent\Models\RoleModel;
use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use DateTimeImmutable;
use DateTimeZone;
use Modules\Forum\Application\Contracts\DiscussionReadsInterface;
use Modules\Forum\Application\Contracts\FollowsInterface;
use Modules\Forum\Application\Contracts\ForumCountersInterface;
use Modules\Forum\Application\Contracts\PostLikesInterface;
use Modules\Forum\Application\DTOs\MovedPostsDTO;
use Modules\Forum\Application\Services\ConversationReader;
use Modules\Forum\Application\Services\ForumModeration;
use Modules\Forum\Application\Services\ForumRedirects;
use Modules\Forum\Application\Services\ForumWriter;
use Modules\Forum\Application\Services\ReportService;
use Modules\Forum\Domain\Entities\Category;
use Modules\Forum\Domain\Entities\Discussion;
use Modules\Forum\Domain\Entities\Post;
use Modules\Forum\Domain\Enums\FollowLevel;
use Modules\Forum\Domain\Enums\ModerationAction;
use Modules\Forum\Domain\Enums\PostStatus;
use Modules\Forum\Domain\Enums\ReportReason;
use Modules\Forum\Domain\Exceptions\DiscussionNotFoundException;
use Modules\Forum\Domain\Exceptions\ForumActionDeniedException;
use Modules\Forum\Domain\Exceptions\ModerationRefusedException;
use Modules\Forum\Domain\Repositories\CategoryRepositoryInterface;
use Modules\Forum\Domain\Repositories\DiscussionRepositoryInterface;
use Modules\Forum\Domain\Repositories\PostRepositoryInterface;
use Modules\Forum\Domain\Repositories\ReportRepositoryInterface;
use Modules\Forum\Domain\Repositories\SubjectDiscussionRepositoryInterface;
use Modules\Forum\Domain\ValueObjects\CategoryAudience;
use Modules\Forum\Domain\ValueObjects\CategoryModerators;
use Modules\Forum\Domain\ValueObjects\DiscussionTopic;
use Modules\Forum\Domain\ValueObjects\PostId;
use Modules\Forum\Domain\ValueObjects\PublicationKey;
use Modules\Forum\Infrastructure\Persistence\Eloquent\Models\ModerationLogModel;
use Modules\Forum\Tests\Support\BuildsForumContent;
use Tests\Support\Modules\ModuleTestCase;
use Tests\Support\Time\UsesUtcStorage;

/**
 * Splitting posts off into a new discussion and merging one discussion into another. Nothing
 * that existed is renumbered: moved posts are numbered from 1 in the new discussion, merged
 * ones after the last post of the one they join, and every old address leads to the new one.
 */
final class SplitAndMergeTest extends ModuleTestCase
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

    public function test_splitting_numbers_the_moved_posts_from_one_in_their_order_and_leaves_gaps(): void
    {
        [$original, $posts] = $this->withReplies(5);
        $other = $this->aCategoryFor(CategoryAudience::everyone(), 'otra');

        $new = $this->moderation()->split($this->moderator, $original->id(), [6, 3, 5], '  Tema   aparte ', $other->id(), 'Se desvió');

        $this->assertSame('Tema aparte', $new->title()->value);
        $this->assertTrue($new->categoryId()->equals($other->id()));
        $this->assertSame($original->number() + 1, $new->number());
        // Started by whoever wrote the first post it holds, when that post was published
        $this->assertSame($posts[3]->authorId(), $new->authorId());
        $this->assertEquals($posts[3]->publishedAt(), $new->createdAt());

        $this->assertSame([[$posts[3]->id()->value, 1], [$posts[5]->id()->value, 2], [$posts[6]->id()->value, 3]], $this->numbering($new));
        // The original keeps its own numbers, with gaps where the posts left
        $this->assertSame([[$posts[1]->id()->value, 1], [$posts[2]->id()->value, 2], [$posts[4]->id()->value, 4]], $this->numbering($original));
    }

    public function test_a_reply_to_the_original_never_takes_a_number_that_moved_away(): void
    {
        [$original] = $this->withReplies(4);

        $this->moderation()->split($this->moderator, $original->id(), [4, 5], 'Tema aparte', $this->general->id());
        $reply = $this->aReply($original, '2026-11-11 10:00:00');

        $this->assertSame(6, $reply->number()?->value);
    }

    public function test_the_original_shows_where_its_posts_went(): void
    {
        [$original] = $this->withReplies(5);
        $new = $this->moderation()->split($this->moderator, $original->id(), [3, 5, 6], 'Tema aparte', $this->general->id());

        $page = app(ConversationReader::class)->open($this->author, $original->id(), null);

        $this->assertSame(
            [[3, 3, 1, $new->id()->value, 1], [5, 6, 2, $new->id()->value, 2]],
            array_map(static fn (MovedPostsDTO $moved): array => [$moved->fromNumber, $moved->throughNumber, $moved->count, $moved->target?->id()->value, $moved->targetNumber], $page->moved),
        );
    }

    public function test_where_posts_went_is_not_named_to_who_cannot_read_it(): void
    {
        [$original] = $this->withReplies(3);
        $staff = $this->aCategoryFor(CategoryAudience::everyone(), 'equipo');
        $new = $this->moderation()->split($this->moderator, $original->id(), [3], 'Tema aparte', $staff->id());
        $staff->changeAccess(CategoryAudience::roles([$this->roleOf($this->moderator)]), CategoryAudience::signedIn(), CategoryModerators::none());
        app(CategoryRepositoryInterface::class)->save($staff);

        $moved = app(ConversationReader::class)->open($this->author, $original->id(), null)->moved;

        $this->assertCount(1, $moved);
        $this->assertNull($moved[0]->target);
        $this->assertNull(app(ForumRedirects::class)->resolve($this->author, (int) $original->number(), 3));
        $this->assertSame($new->id()->value, app(ForumRedirects::class)->resolve($this->moderator, (int) $original->number(), 3)?->discussion->id()->value);
    }

    public function test_the_old_address_of_a_moved_post_leads_to_its_new_place(): void
    {
        [$original] = $this->withReplies(5);
        $new = $this->moderation()->split($this->moderator, $original->id(), [3, 5], 'Tema aparte', $this->general->id());

        $target = app(ForumRedirects::class)->resolve(null, (int) $original->number(), 5);

        $this->assertSame($new->id()->value, $target?->discussion->id()->value);
        $this->assertSame(2, $target->postNumber);
        // A post that never moved has no redirect
        $this->assertNull(app(ForumRedirects::class)->resolve(null, (int) $original->number(), 4));
    }

    public function test_merging_appends_the_posts_after_the_last_in_date_order_and_removes_the_discussion(): void
    {
        [$into] = $this->aDiscussion($this->general, '2026-11-10 10:00:00', strtolower($this->author->id), title: 'Destino');
        $this->aReply($into, '2026-11-10 13:00:00');
        [$absorbed, $first] = $this->aDiscussion($this->general, '2026-11-10 11:00:00', title: 'Absorbido');
        $second = $this->aReply($absorbed, '2026-11-10 12:00:00');
        $third = $this->aReply($absorbed, '2026-11-10 14:00:00');

        $result = $this->moderation()->merge($this->moderator, $absorbed->id(), $into->id(), 'Mismo asunto');

        $this->assertSame($into->id()->value, $result->id()->value);
        $this->assertNull(app(DiscussionRepositoryInterface::class)->find($absorbed->id()));
        $numbering = $this->numbering($into);
        $this->assertCount(5, $numbering);
        $this->assertSame([[$first->id()->value, 3], [$second->id()->value, 4], [$third->id()->value, 5]], array_slice($numbering, 2));
        // Dates are kept
        $this->assertEquals($first->publishedAt(), app(PostRepositoryInterface::class)->findOrFail($first->id())->publishedAt());
    }

    public function test_the_merged_discussion_and_its_posts_lead_to_their_new_place(): void
    {
        [$into] = $this->withReplies(2);
        [$absorbed] = $this->aDiscussion($this->general, '2026-11-10 19:00:00', title: 'Absorbido');
        $this->aReply($absorbed, '2026-11-10 19:10:00');

        $this->moderation()->merge($this->moderator, $absorbed->id(), $into->id());

        $redirects = app(ForumRedirects::class);
        $whole = $redirects->resolve(null, (int) $absorbed->number(), null);
        $this->assertSame($into->id()->value, $whole?->discussion->id()->value);
        $this->assertNull($whole->postNumber);
        $this->assertSame(5, $redirects->resolve(null, (int) $absorbed->number(), 2)?->postNumber);
        $this->assertSame(4, $redirects->resolve(null, (int) $absorbed->number(), 1)?->postNumber);
        // A number the absorbed discussion never had leads to the discussion it joined
        $this->assertNull($redirects->resolve(null, (int) $absorbed->number(), 9)?->postNumber);
    }

    public function test_merging_carries_posts_awaiting_approval_as_they_are(): void
    {
        [$into] = $this->withReplies(1);
        [$absorbed] = $this->aDiscussion($this->general, '2026-11-10 19:00:00', title: 'Absorbido');
        $pending = $this->aReply($absorbed, '2026-11-10 19:10:00', needsApproval: true);

        $this->moderation()->merge($this->moderator, $absorbed->id(), $into->id());

        $stored = app(PostRepositoryInterface::class)->findOrFail($pending->id());
        $this->assertTrue($stored->discussionId()->equals($into->id()));
        $this->assertSame(PostStatus::Pending, $stored->status());
        $this->assertNull($stored->number());
        $this->assertSame(4, $this->moderation()->approve($this->moderator, $pending->id())->number()?->value);
    }

    public function test_the_number_of_a_merged_discussion_is_never_given_again(): void
    {
        [$into] = $this->withReplies(1);
        [$absorbed] = $this->aDiscussion($this->general, '2026-11-10 19:00:00', title: 'Absorbido');

        $this->moderation()->merge($this->moderator, $absorbed->id(), $into->id());
        [$next] = $this->aDiscussion($this->general, '2026-11-11 10:00:00', title: 'Otro tema');

        $this->assertSame((int) $absorbed->number() + 1, $next->number());
    }

    public function test_redirects_follow_posts_and_discussions_through_later_moves(): void
    {
        [$original] = $this->withReplies(4);
        $split = $this->moderation()->split($this->moderator, $original->id(), [4, 5], 'Tema aparte', $this->general->id());
        [$last] = $this->aDiscussion($this->general, '2026-11-12 10:00:00', title: 'Último');
        $this->moderation()->merge($this->moderator, $split->id(), $last->id());
        [$final] = $this->aDiscussion($this->general, '2026-11-13 10:00:00', title: 'Final');
        $this->moderation()->merge($this->moderator, $last->id(), $final->id());

        $redirects = app(ForumRedirects::class);
        // Post 5 of the original went to the split (2), which joined «Último» (3), which joined
        // «Final» after its first post, before «Último»'s own (published later): 3 there
        $five = $redirects->resolve(null, (int) $original->number(), 5);
        $this->assertSame($final->id()->value, $five?->discussion->id()->value);
        $this->assertSame(3, $five->postNumber);
        $this->assertSame($final->id()->value, $redirects->resolve(null, (int) $split->number(), null)?->discussion->id()->value);
        $this->assertSame(2, $redirects->resolve(null, (int) $split->number(), 1)?->postNumber);
        $this->assertSame(4, $redirects->resolve(null, (int) $last->number(), 1)?->postNumber);
        $this->assertSame($final->id()->value, $redirects->resolve(null, (int) $last->number(), null)?->discussion->id()->value);
    }

    public function test_counters_of_both_discussions_and_categories_follow_the_posts(): void
    {
        $other = $this->aCategoryFor(CategoryAudience::everyone(), 'otra');
        [$original] = $this->withReplies(4);
        $counters = app(ForumCountersInterface::class);

        $new = $this->moderation()->split($this->moderator, $original->id(), [4, 5], 'Tema aparte', $other->id());

        $this->assertSame(3, $counters->discussion($original->id())?->postCount);
        $this->assertSame(2, $counters->discussion($new->id())?->postCount);
        $general = $counters->category($this->general->id());
        $moved = $counters->category($other->id());
        $this->assertSame([3, 2, 1], [$general?->postCount, $moved?->postCount, $moved?->discussionCount]);

        [$into] = $this->aDiscussion($this->general, '2026-11-12 10:00:00', title: 'Destino');
        $this->moderation()->merge($this->moderator, $new->id(), $into->id());

        $joined = $counters->discussion($into->id());
        $this->assertSame(3, $joined?->postCount);
        $this->assertSame([6, 0], [$counters->category($this->general->id())?->postCount, $counters->category($other->id())?->discussionCount]);
        // Merging older posts in does not make the discussion look older: its latest post stays its latest activity
        $this->assertSame('2026-11-12 10:00', $joined->lastActivityAt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i'));
    }

    public function test_read_marks_carry_over(): void
    {
        [$original] = $this->withReplies(5);
        $reads = app(DiscussionReadsInterface::class);
        $reader = strtolower($this->anAccount()->id);
        $behind = strtolower($this->anAccount()->id);
        $reads->advance($reader, $original->id(), 5, new DateTimeImmutable('2026-11-11 09:00:00'));
        $reads->advance($behind, $original->id(), 2, new DateTimeImmutable('2026-11-11 09:00:00'));

        $new = $this->moderation()->split($this->moderator, $original->id(), [3, 5, 6], 'Tema aparte', $this->general->id());

        // Read through 5 in the original: 3 and 5 were read, which are 1 and 2 now
        $this->assertSame(2, $reads->readThrough($reader, $new->id(), $new->categoryId()));
        $this->assertSame(0, $reads->readThrough($behind, $new->id(), $new->categoryId()));
        $this->assertSame(5, $reads->readThrough($reader, $original->id(), $original->categoryId()));

        [$into] = $this->aDiscussion($this->general, '2026-11-12 10:00:00', title: 'Destino');
        $this->aReply($into, '2026-11-12 10:10:00');
        $reads->advance($reader, $into->id(), 2, new DateTimeImmutable('2026-11-12 11:00:00'));
        $reads->advance($behind, $into->id(), 1, new DateTimeImmutable('2026-11-12 11:00:00'));
        $reads->advance($behind, $new->id(), 3, new DateTimeImmutable('2026-11-12 11:00:00'));

        $this->moderation()->merge($this->moderator, $new->id(), $into->id());

        // Whoever had read all of it reads on through what they read of the merged one (2 → 4)
        $this->assertSame(4, $reads->readThrough($reader, $into->id(), $into->categoryId()));
        // Whoever had not keeps their place: better unread than lost
        $this->assertSame(1, $reads->readThrough($behind, $into->id(), $into->categoryId()));
    }

    public function test_a_category_read_mark_never_counts_newer_posts_as_read_after_a_merge(): void
    {
        [$into] = $this->aDiscussion($this->general, '2026-11-12 10:00:00', title: 'Destino');
        [$absorbed] = $this->aDiscussion($this->general, '2026-11-01 10:00:00', title: 'Viejo');
        $reader = strtolower($this->anAccount()->id);
        $reads = app(DiscussionReadsInterface::class);
        $reads->markCategoriesRead($reader, [$this->general->id()], new DateTimeImmutable('2026-11-05 00:00:00'));

        $this->moderation()->merge($this->moderator, $absorbed->id(), $into->id());

        // Post 2 (the merged one, from 1 November) was published before the mark, but post 1 was not
        $this->assertSame(0, $reads->readThrough($reader, $into->id(), $into->categoryId()));
    }

    public function test_follows_carry_over(): void
    {
        [$original] = $this->withReplies(3);
        $follows = app(FollowsInterface::class);
        $at = new DateTimeImmutable('2026-11-11 09:00:00');
        $follower = strtolower($this->anAccount()->id);
        $quiet = strtolower($this->anAccount()->id);
        $follows->setDiscussionLevel($follower, $original->id(), FollowLevel::Following, $at);
        $follows->setDiscussionLevel($quiet, $original->id(), FollowLevel::Normal, $at);

        $new = $this->moderation()->split($this->moderator, $original->id(), [3, 4], 'Tema aparte', $this->general->id());

        $this->assertSame(FollowLevel::Following, $follows->discussionLevel($follower, $new->id()));
        $this->assertSame(FollowLevel::Normal, $follows->discussionLevel($quiet, $new->id()));

        [$into] = $this->aDiscussion($this->general, '2026-11-12 10:00:00', title: 'Destino');
        $chose = strtolower($this->anAccount()->id);
        $follows->setDiscussionLevel($chose, $into->id(), FollowLevel::Normal, $at);
        $follows->setDiscussionLevel($chose, $new->id(), FollowLevel::Following, $at);
        $follows->setDiscussionLevel($quiet, $into->id(), FollowLevel::Following, $at);

        $this->moderation()->merge($this->moderator, $new->id(), $into->id());

        $this->assertSame(FollowLevel::Following, $follows->discussionLevel($follower, $into->id()));
        // What someone chose for the discussion it joined stays theirs
        $this->assertSame(FollowLevel::Normal, $follows->discussionLevel($chose, $into->id()));
        $this->assertSame(FollowLevel::Following, $follows->discussionLevel($quiet, $into->id()));
    }

    public function test_likes_reports_and_topics_stay_with_what_they_belong_to(): void
    {
        [$original, $posts] = $this->withReplies(3);
        app(DiscussionRepositoryInterface::class)->replaceTopics($original->id(), [new DiscussionTopic('game-system.dnd', 'D&D')]);
        $liker = $this->anAccount();
        app(PostLikesInterface::class)->add($posts[3]->id(), strtolower($liker->id), new DateTimeImmutable('2026-11-11 09:00:00'));
        app(ReportService::class)->report($liker, $posts[3]->id(), ReportReason::OffTopic, null);

        $new = $this->moderation()->split($this->moderator, $original->id(), [3], 'Tema aparte', $this->general->id());

        $likes = app(PostLikesInterface::class)->of([$posts[3]->id()], strtolower($liker->id), 3);
        $this->assertSame(1, $likes[$posts[3]->id()->value]->count);
        $this->assertCount(1, app(ReportRepositoryInterface::class)->openOf($posts[3]->id()));
        $this->assertSame(['game-system.dnd'], array_map(static fn (DiscussionTopic $topic): string => $topic->key, app(DiscussionRepositoryInterface::class)->topicsOf($new->id())));
    }

    public function test_both_actions_are_logged_with_the_other_discussion(): void
    {
        [$original] = $this->withReplies(3);
        $new = $this->moderation()->split($this->moderator, $original->id(), [3, 4], 'Tema aparte', $this->general->id(), 'Se desvió');
        [$into] = $this->aDiscussion($this->general, '2026-11-12 10:00:00', title: 'Destino');
        $this->moderation()->merge($this->moderator, $new->id(), $into->id(), 'Repetido');

        $split = ModerationLogModel::query()->where('action', ModerationAction::DiscussionSplit->value)->sole();
        $this->assertSame($original->id()->value, $split->discussion_id);
        $this->assertSame('Se desvió', $split->reason);
        $this->assertSame([$new->number(), 'Tema aparte', 2], [$split->details['to_number'] ?? null, $split->details['to_title'] ?? null, $split->details['posts'] ?? null]);

        $merged = ModerationLogModel::query()->where('action', ModerationAction::DiscussionMerged->value)->sole();
        $this->assertSame($new->id()->value, $merged->discussion_id);
        $this->assertSame('Tema aparte', $merged->details['discussion_title'] ?? null);
        $this->assertSame([$into->number(), 'Destino', 2], [$merged->details['to_number'] ?? null, $merged->details['to_title'] ?? null, $merged->details['posts'] ?? null]);
    }

    public function test_someone_who_does_not_moderate_is_refused_and_nothing_changes(): void
    {
        [$original] = $this->withReplies(2);
        [$into] = $this->aDiscussion($this->general, '2026-11-12 10:00:00', title: 'Destino');
        $reader = $this->anAccount();

        foreach ([
            fn () => $this->moderation()->split($reader, $original->id(), [2], 'Tema aparte', $this->general->id()),
            fn () => $this->moderation()->merge($reader, $original->id(), $into->id()),
        ] as $action) {
            try {
                $action();
                $this->fail('A reader split or merged a discussion.');
            } catch (ForumActionDeniedException) {
            }
        }

        $this->assertCount(3, $this->numbering($original));
        $this->assertSame(0, ModerationLogModel::query()->count());
    }

    public function test_a_category_moderator_only_restructures_inside_what_they_moderate(): void
    {
        $leader = $this->anAccount();
        $team = $this->aCategoryFor(CategoryAudience::everyone(), 'equipo');
        $team->changeAccess(CategoryAudience::everyone(), CategoryAudience::signedIn(), new CategoryModerators([$this->roleOf($leader)]));
        app(CategoryRepositoryInterface::class)->save($team);
        [$original] = $this->aDiscussion($team, '2026-11-10 10:00:00', title: 'Del equipo');
        $this->aReply($original, '2026-11-10 10:10:00');
        [$elsewhere] = $this->aDiscussion($this->general, '2026-11-10 11:00:00', title: 'De otra');

        $this->assertSame([$team->id()->value], array_map(static fn (Category $category): string => $category->id()->value, $this->moderation()->splitCategories($leader, $original)));

        try {
            $this->moderation()->split($leader, $original->id(), [2], 'Tema aparte', $this->general->id());
            $this->fail('A category moderator split into a category they do not moderate.');
        } catch (ModerationRefusedException $exception) {
            $this->assertSame('move_target', $exception->reason);
        }

        try {
            $this->moderation()->merge($leader, $original->id(), $elsewhere->id());
            $this->fail('A category moderator merged into a discussion they do not moderate.');
        } catch (ModerationRefusedException $exception) {
            $this->assertSame('merge_target', $exception->reason);
        }

        $this->assertTrue($this->moderation()->split($leader, $original->id(), [2], 'Tema aparte', $team->id())->categoryId()->equals($team->id()));
    }

    public function test_publication_discussions_are_never_split_nor_merged(): void
    {
        [$automatic] = $this->withReplies(2);
        $record = app(SubjectDiscussionRepositoryInterface::class)->lockOrCreate(new PublicationKey('game-table', '0d2f3c4b-1a2b-4c3d-8e9f-0a1b2c3d4e5f'));
        $record->announce($automatic->id(), PostId::generate(), [], true);
        app(SubjectDiscussionRepositoryInterface::class)->save($record);
        [$plain] = $this->aDiscussion($this->general, '2026-11-12 10:00:00', title: 'Normal');

        $this->assertFalse($this->moderation()->canRestructure($this->moderator, $automatic));
        $this->assertTrue($this->moderation()->canRestructure($this->moderator, $plain));

        foreach ([
            fn () => $this->moderation()->split($this->moderator, $automatic->id(), [2], 'Tema aparte', $this->general->id()),
            fn () => $this->moderation()->merge($this->moderator, $automatic->id(), $plain->id()),
            fn () => $this->moderation()->merge($this->moderator, $plain->id(), $automatic->id()),
        ] as $action) {
            try {
                $action();
                $this->fail('A publication discussion was split or merged.');
            } catch (ModerationRefusedException $exception) {
                $this->assertSame('automatic_discussion', $exception->reason);
            }
        }

        $this->assertNotNull(app(DiscussionRepositoryInterface::class)->find($plain->id()));
    }

    public function test_a_discussion_awaiting_approval_is_neither_split_nor_merged(): void
    {
        [$waiting] = $this->aDiscussion($this->general, '2026-11-10 10:00:00', strtolower($this->author->id), needsApproval: true, title: 'En espera');
        [$plain] = $this->aDiscussion($this->general, '2026-11-12 10:00:00', title: 'Normal');

        foreach ([
            fn () => $this->moderation()->merge($this->moderator, $waiting->id(), $plain->id()),
            fn () => $this->moderation()->merge($this->moderator, $plain->id(), $waiting->id()),
        ] as $action) {
            try {
                $action();
                $this->fail('A discussion awaiting approval was merged.');
            } catch (ModerationRefusedException $exception) {
                $this->assertSame('awaiting_approval', $exception->reason);
            }
        }
    }

    public function test_only_numbered_posts_after_the_first_may_be_split_off(): void
    {
        [$original] = $this->withReplies(2);
        $this->aReply($original, '2026-11-11 10:00:00', needsApproval: true);

        foreach ([[], [1], [1, 2], [9], [2, 2, 3, 0]] as $numbers) {
            try {
                $this->moderation()->split($this->moderator, $original->id(), $numbers, 'Tema aparte', $this->general->id());
                $this->fail('An invalid selection was split off: '.implode(',', $numbers));
            } catch (ModerationRefusedException $exception) {
                $this->assertSame('split_selection', $exception->reason);
            }
        }

        try {
            $this->moderation()->split($this->moderator, $original->id(), [2], 'ab', $this->general->id());
            $this->fail('A title out of bounds was accepted.');
        } catch (ModerationRefusedException $exception) {
            $this->assertSame('title', $exception->reason);
        }

        // The three numbered posts and the one awaiting approval, untouched
        $this->assertCount(4, $this->numbering($original));
    }

    public function test_hidden_and_deleted_posts_move_as_they_are(): void
    {
        [$original, $posts] = $this->withReplies(3);
        $this->moderation()->hidePost($this->moderator, $posts[2]->id(), 'Fuera de lugar');

        $new = $this->moderation()->split($this->moderator, $original->id(), [2, 3], 'Tema aparte', $this->general->id());

        $moved = app(PostRepositoryInterface::class)->findOrFail($posts[2]->id());
        $this->assertSame(PostStatus::Hidden, $moved->status());
        $this->assertSame('Fuera de lugar', $moved->hiddenReason());
        $this->assertTrue($moved->discussionId()->equals($new->id()));
    }

    public function test_a_discussion_is_never_merged_into_itself_nor_into_one_the_moderator_cannot_read(): void
    {
        [$original] = $this->withReplies(1);

        try {
            $this->moderation()->merge($this->moderator, $original->id(), $original->id());
            $this->fail('A discussion was merged into itself.');
        } catch (ModerationRefusedException $exception) {
            $this->assertSame('merge_target', $exception->reason);
        }

        $this->expectException(DiscussionNotFoundException::class);
        $this->moderation()->mergeTarget($this->moderator, $original, 999);
    }

    public function test_the_merge_target_is_found_by_its_number(): void
    {
        [$original] = $this->withReplies(1);
        [$into] = $this->aDiscussion($this->general, '2026-11-12 10:00:00', title: 'Destino');

        $this->assertSame($into->id()->value, $this->moderation()->mergeTarget($this->moderator, $original, (int) $into->number())->id()->value);

        $this->expectException(ModerationRefusedException::class);
        $this->moderation()->mergeTarget($this->moderator, $original, (int) $original->number());
    }

    /**
     * A discussion by the author with replies from 18:10 every ten minutes: posts by number.
     *
     * @return array{Discussion, array<int, Post>}
     */
    private function withReplies(int $replies): array
    {
        [$discussion, $first] = $this->aDiscussion($this->general, '2026-11-10 18:00:00', strtolower($this->author->id));
        $posts = [1 => $first];
        for ($index = 1; $index <= $replies; $index++) {
            $posts[$index + 1] = app(ForumWriter::class)->reply(
                $discussion->id(),
                PostId::generate(),
                strtolower($this->anAccount()->id),
                self::aBody("Respuesta {$index}"),
                new DateTimeImmutable(sprintf('2026-11-10 18:%02d:00', $index * 10)),
                false,
            );
        }

        return [$discussion, $posts];
    }

    /**
     * @return list<array{string, int|null}> post id and number, in the discussion's order
     */
    private function numbering(Discussion $discussion): array
    {
        return array_map(
            static fn (Post $post): array => [$post->id()->value, $post->number()?->value],
            app(PostRepositoryInterface::class)->inDiscussion($discussion->id()),
        );
    }

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
