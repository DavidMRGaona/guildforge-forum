<?php

declare(strict_types=1);

namespace Modules\Forum\Tests\Feature\Activity;

use App\Application\Activity\Contracts\ActivitySourceInterface;
use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use DateTimeImmutable;
use Modules\Forum\Application\Services\ForumWriter;
use Modules\Forum\Domain\Entities\Category;
use Modules\Forum\Domain\ValueObjects\CategoryAudience;
use Modules\Forum\Domain\ValueObjects\PostId;
use Modules\Forum\Infrastructure\Activity\PostActivitySource;
use Modules\Forum\Tests\Support\BuildsForumContent;
use Tests\Support\Activity\ActivitySourceTestCase;

/**
 * `forum.post` keeps the core's activity contract (core#774).
 */
final class PostActivitySourceTest extends ActivitySourceTestCase
{
    use BuildsForumContent;

    protected ?string $moduleName = 'forum';

    private ?Category $general = null;

    protected function activitySource(): ActivitySourceInterface
    {
        return $this->app->make(PostActivitySource::class);
    }

    protected function createCountedActivitySubject(): string
    {
        $this->general ??= $this->aCategoryFor(CategoryAudience::everyone(), 'general');
        $author = strtolower((string) UserModel::factory()->create()->id);
        [$discussion] = $this->aDiscussion($this->general, authorId: $author);

        return app(ForumWriter::class)->reply($discussion->id(), PostId::generate(), $author, self::aBody('Hola'), new DateTimeImmutable('2026-11-11 10:00:00'), false)->id()->value;
    }
}
