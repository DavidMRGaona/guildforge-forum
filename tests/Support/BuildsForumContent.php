<?php

declare(strict_types=1);

namespace Modules\Forum\Tests\Support;

use DateTimeImmutable;
use Modules\Forum\Application\Services\ForumWriter;
use Modules\Forum\Domain\Entities\Category;
use Modules\Forum\Domain\Entities\Discussion;
use Modules\Forum\Domain\Entities\Post;
use Modules\Forum\Domain\Repositories\CategoryRepositoryInterface;
use Modules\Forum\Domain\ValueObjects\CategoryId;
use Modules\Forum\Domain\ValueObjects\CategorySlug;
use Modules\Forum\Domain\ValueObjects\DiscussionId;
use Modules\Forum\Domain\ValueObjects\DiscussionTitle;
use Modules\Forum\Domain\ValueObjects\PostBody;
use Modules\Forum\Domain\ValueObjects\PostId;

/**
 * Categories, discussions and posts written the way the forum writes them.
 */
trait BuildsForumContent
{
    protected function aCategory(string $slug = 'general', int $position = 0): Category
    {
        $category = new Category(CategoryId::generate(), ucfirst(str_replace('-', ' ', $slug)), new CategorySlug($slug), null, $position);
        app(CategoryRepositoryInterface::class)->save($category);

        return $category;
    }

    /**
     * @return array{Discussion, Post}
     */
    protected function aDiscussion(Category $category, string $at = '2026-11-10 18:00:00', ?string $authorId = null, bool $needsApproval = false): array
    {
        $discussion = Discussion::start(DiscussionId::generate(), $category->id(), $authorId, new DiscussionTitle('Sesión del viernes'), new DateTimeImmutable($at));
        $post = app(ForumWriter::class)->startDiscussion($discussion, PostId::generate(), self::aBody('Primer mensaje'), $needsApproval);

        return [$discussion, $post];
    }

    protected function aReply(Discussion $discussion, string $at, bool $needsApproval = false, string $text = 'Respuesta'): Post
    {
        return app(ForumWriter::class)->reply($discussion->id(), PostId::generate(), null, self::aBody($text), new DateTimeImmutable($at), $needsApproval);
    }

    protected static function aBody(string $text): PostBody
    {
        return new PostBody(['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $text]]]]], $text);
    }
}
