<?php

declare(strict_types=1);

namespace Modules\Forum\Tests\Feature\Activity;

use App\Application\Activity\Contracts\ActivitySourceInterface;
use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use Modules\Forum\Domain\Entities\Category;
use Modules\Forum\Domain\ValueObjects\CategoryAudience;
use Modules\Forum\Infrastructure\Activity\DiscussionActivitySource;
use Modules\Forum\Tests\Support\BuildsForumContent;
use Tests\Support\Activity\ActivitySourceTestCase;

/**
 * `forum.discussion` keeps the core's activity contract (core#774).
 */
final class DiscussionActivitySourceTest extends ActivitySourceTestCase
{
    use BuildsForumContent;

    protected ?string $moduleName = 'forum';

    private ?Category $general = null;

    protected function activitySource(): ActivitySourceInterface
    {
        return $this->app->make(DiscussionActivitySource::class);
    }

    protected function createCountedActivitySubject(): string
    {
        $this->general ??= $this->aCategoryFor(CategoryAudience::signedIn(), 'general');
        [$discussion] = $this->aDiscussion($this->general, authorId: strtolower((string) UserModel::factory()->create()->id));

        return $discussion->id()->value;
    }
}
