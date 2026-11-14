<?php

declare(strict_types=1);

namespace Modules\Forum\Tests\Integration\Notices;

use App\Application\Mail\Services\MailCategoryRegistryInterface;
use App\Application\Mail\Services\MailSubjectMuteServiceInterface;
use App\Application\Modules\DTOs\SlotRegistrationDTO;
use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use Modules\Forum\Application\Services\CategoryManager;
use Modules\Forum\Application\Services\ForumMailSubjects;
use Modules\Forum\Domain\Enums\PostNoticeKind;
use Modules\Forum\ForumServiceProvider;
use Modules\Forum\Notifications\ForumMailCategories;
use Modules\Forum\Notifications\MentionedNotification;
use Modules\Forum\Notifications\PostLikedNotification;
use Modules\Forum\Notifications\ReplyPostedNotification;
use Modules\Forum\Tests\Support\BuildsForumContent;
use Tests\Support\Modules\ModuleTestCase;
use Tests\Support\Time\UsesUtcStorage;

/**
 * The «Foro» section of the profile's notices, its «Foro» tab, and what deleting a category forgets.
 */
final class ForumMailCategoriesTest extends ModuleTestCase
{
    use BuildsForumContent;
    use UsesUtcStorage;

    protected ?string $moduleName = 'forum';

    protected bool $autoEnableModule = true;

    public function test_replies_and_new_discussions_may_wait_for_the_digest_mentions_go_at_once_and_likes_stay_on_the_site(): void
    {
        $registry = app(MailCategoryRegistryInterface::class);

        $replies = $registry->find(ForumMailCategories::REPLIES);
        $discussions = $registry->find(ForumMailCategories::DISCUSSIONS);
        $mentions = $registry->find(ForumMailCategories::MENTIONS);
        $likes = $registry->find(ForumMailCategories::LIKES);

        $this->assertNotNull($replies);
        $this->assertNotNull($discussions);
        $this->assertNotNull($mentions);
        $this->assertNotNull($likes);
        $this->assertTrue($replies->digestible && $replies->site && $replies->mail);
        $this->assertTrue($discussions->digestible && $discussions->site && $discussions->mail);
        $this->assertTrue(! $mentions->digestible && $mentions->site && $mentions->mail);
        $this->assertTrue($likes->site && ! $likes->mail);
        foreach ([$replies, $discussions, $mentions, $likes] as $category) {
            $this->assertFalse($category->isMandatory());
        }

        $this->assertSame($replies, $registry->forNotification(new ReplyPostedNotification('00000000-0000-4000-8000-000000000000')));
        $this->assertSame($mentions, $registry->forNotification(new MentionedNotification('00000000-0000-4000-8000-000000000000', PostNoticeKind::Quote)));
        $this->assertSame($likes, $registry->forNotification(new PostLikedNotification('00000000-0000-4000-8000-000000000000', '00000000-0000-4000-8000-000000000001')));
    }

    public function test_deleting_a_category_forgets_its_mutes(): void
    {
        $user = UserModel::factory()->create();
        $category = $this->aCategory('vacia');
        app(MailSubjectMuteServiceInterface::class)->mute($user, ForumMailSubjects::category($category));

        app(CategoryManager::class)->delete($category->id(), null);

        $this->assertSame([], app(MailSubjectMuteServiceInterface::class)->mutedFor($user));
    }

    public function test_the_profile_has_a_forum_tab_that_loads_its_own_data(): void
    {
        $provider = app()->getProvider(ForumServiceProvider::class);
        $this->assertInstanceOf(ForumServiceProvider::class, $provider);
        $slot = collect($provider->registerSlots())
            ->first(fn (SlotRegistrationDTO $slot): bool => ($slot->profileTab['tabId'] ?? null) === 'forum');

        $this->assertNotNull($slot);
        $this->assertSame('profile-sections', $slot->slot);
        $this->assertSame('components/profile/ForumPreferences.vue', $slot->component);
        // It loads its own data: the page only hands it whether the forum is visible (its visibleKey)
        $this->assertSame(['forumVisible'], $slot->dataKeys);
        $this->assertSame('forumVisible', $slot->profileTab['visibleKey'] ?? null);
        $this->assertFileExists(dirname(__DIR__, 3).'/resources/js/'.$slot->component);
    }
}
