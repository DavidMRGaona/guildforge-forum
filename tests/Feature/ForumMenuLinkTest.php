<?php

declare(strict_types=1);

namespace Modules\Forum\Tests\Feature;

use App\Infrastructure\Navigation\Persistence\Eloquent\Models\MenuItemModel;
use Modules\Forum\Application\Contracts\PostLinksInterface;
use Modules\Forum\Database\Seeders\ForumMenuLinkSeeder;
use Modules\Forum\Tests\Support\BuildsForumContent;
use Tests\Support\Modules\ModuleTestCase;
use Tests\Support\Time\UsesUtcStorage;

/**
 * The module adds «Foro» to the header menu once, as one of its links, and offers its front
 * page in the menu editor. Quotes link to the quoted post's place.
 */
final class ForumMenuLinkTest extends ModuleTestCase
{
    use BuildsForumContent;
    use UsesUtcStorage;

    protected ?string $moduleName = 'forum';

    protected bool $autoEnableModule = true;

    public function test_the_link_is_added_once_as_the_modules_own(): void
    {
        (new ForumMenuLinkSeeder)->run();
        (new ForumMenuLinkSeeder)->run();

        // sole() fails unless there is exactly one
        $link = MenuItemModel::query()->where('route', ForumMenuLinkSeeder::ROUTE)->sole();
        $this->assertSame('forum', $link->module);
        $this->assertSame('Foro', $link->label);
        $this->assertSame('header', $link->location->value);
        $this->assertTrue($link->is_active);
    }

    public function test_a_link_moved_or_relabelled_by_an_admin_is_left_alone(): void
    {
        (new ForumMenuLinkSeeder)->run();
        MenuItemModel::query()->where('route', ForumMenuLinkSeeder::ROUTE)->update(['label' => 'Comunidad', 'location' => 'footer']);

        (new ForumMenuLinkSeeder)->run();

        $this->assertSame(['Comunidad'], MenuItemModel::query()->where('route', ForumMenuLinkSeeder::ROUTE)->pluck('label')->all());
    }

    public function test_quotes_link_to_the_quoted_posts_place(): void
    {
        $this->useUtcStorage();
        [$discussion, $first] = $this->aDiscussion($this->aCategory(), title: 'Mesa del viernes');
        $reply = $this->aReply($discussion, '2026-11-10 19:00:00');
        $links = app(PostLinksInterface::class);

        $this->assertSame('/foro/t/1-mesa-del-viernes', $links->urlFor($first, $discussion));
        $this->assertSame('/foro/t/1-mesa-del-viernes/2', $links->urlFor($reply, $discussion));
    }
}
