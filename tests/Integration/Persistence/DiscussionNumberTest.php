<?php

declare(strict_types=1);

namespace Modules\Forum\Tests\Integration\Persistence;

use DateTimeImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Modules\Forum\Application\Services\ForumWriter;
use Modules\Forum\Domain\Entities\Discussion;
use Modules\Forum\Domain\Repositories\DiscussionRepositoryInterface;
use Modules\Forum\Domain\ValueObjects\DiscussionId;
use Modules\Forum\Domain\ValueObjects\DiscussionTitle;
use Modules\Forum\Domain\ValueObjects\PostId;
use Modules\Forum\Infrastructure\Persistence\Eloquent\Repositories\EloquentDiscussionRepository;
use Modules\Forum\Tests\Support\BuildsForumContent;
use Modules\Forum\Tests\Support\StaleNumberDiscussions;
use Tests\Support\Modules\ModuleTestCase;
use Tests\Support\Time\UsesUtcStorage;

/**
 * Each discussion gets a public number when it starts, the one in its address: 1, 2, 3… without
 * repeats, also when two start at the same moment.
 */
final class DiscussionNumberTest extends ModuleTestCase
{
    use BuildsForumContent;
    use UsesUtcStorage;

    protected ?string $moduleName = 'forum';

    protected bool $autoEnableModule = true;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useUtcStorage();
    }

    public function test_discussions_are_numbered_in_the_order_they_start(): void
    {
        $category = $this->aCategory();
        [$first] = $this->aDiscussion($category);
        [$second] = $this->aDiscussion($this->aCategory('otra'), '2026-11-10 19:00:00');

        $this->assertSame(1, $first->number());
        $this->assertSame(2, $second->number());
        $found = app(DiscussionRepositoryInterface::class)->findByNumber(2);
        $this->assertInstanceOf(Discussion::class, $found);
        $this->assertSame(2, $found->number());
        $this->assertTrue($found->id()->equals($second->id()));
        $this->assertNull(app(DiscussionRepositoryInterface::class)->findByNumber(3));
    }

    public function test_a_number_taken_meanwhile_is_retried_with_the_next_one(): void
    {
        $category = $this->aCategory();
        $this->aDiscussion($category);

        // Another start took number 2 between reading the next number and saving
        $this->app->instance(DiscussionRepositoryInterface::class, new StaleNumberDiscussions(new EloquentDiscussionRepository, staleCalls: 1, staleNumber: 1));

        $discussion = Discussion::start(DiscussionId::generate(), $category->id(), null, new DiscussionTitle('Otra'), new DateTimeImmutable('2026-11-10 20:00:00'));
        app(ForumWriter::class)->startDiscussion($discussion, PostId::generate(), self::aBody('Hola'), needsApproval: false);

        $this->assertSame(2, $discussion->number());
        $this->assertSame(2, app(DiscussionRepositoryInterface::class)->find($discussion->id())?->number());
    }

    public function test_it_gives_up_after_a_few_attempts(): void
    {
        $category = $this->aCategory();
        $this->aDiscussion($category);

        $this->app->instance(DiscussionRepositoryInterface::class, new StaleNumberDiscussions(new EloquentDiscussionRepository, staleCalls: 99, staleNumber: 1));

        $discussion = Discussion::start(DiscussionId::generate(), $category->id(), null, new DiscussionTitle('Otra'), new DateTimeImmutable('2026-11-10 20:00:00'));

        $this->expectException(UniqueConstraintViolationException::class);
        app(ForumWriter::class)->startDiscussion($discussion, PostId::generate(), self::aBody('Hola'), needsApproval: false);
    }
}
