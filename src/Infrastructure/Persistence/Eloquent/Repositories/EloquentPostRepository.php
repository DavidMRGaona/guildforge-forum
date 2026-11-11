<?php

declare(strict_types=1);

namespace Modules\Forum\Infrastructure\Persistence\Eloquent\Repositories;

use Modules\Forum\Domain\Entities\Post;
use Modules\Forum\Domain\Enums\PostStatus;
use Modules\Forum\Domain\Exceptions\PostNotFoundException;
use Modules\Forum\Domain\Repositories\PostRepositoryInterface;
use Modules\Forum\Domain\ValueObjects\DiscussionId;
use Modules\Forum\Domain\ValueObjects\PostBody;
use Modules\Forum\Domain\ValueObjects\PostId;
use Modules\Forum\Domain\ValueObjects\PostNumber;
use Modules\Forum\Infrastructure\Persistence\Eloquent\Models\PostModel;

final class EloquentPostRepository implements PostRepositoryInterface
{
    public function save(Post $post): void
    {
        PostModel::query()->updateOrCreate(['id' => $post->id()->value], [
            'discussion_id' => $post->discussionId()->value,
            'number' => $post->number()?->value,
            'author_id' => $post->authorId(),
            'status' => $post->status()->value,
            'body' => $post->body()->document,
            'plain_text' => $post->body()->plainText,
            'published_at' => $post->publishedAt(),
            'edited_at' => $post->editedAt(),
            'created_at' => $post->createdAt(),
        ]);
    }

    public function find(PostId $id): ?Post
    {
        $model = PostModel::query()->find($id->value);

        return $model instanceof PostModel ? $this->toEntity($model) : null;
    }

    public function findOrFail(PostId $id): Post
    {
        return $this->find($id) ?? throw PostNotFoundException::withId($id->value);
    }

    public function findMany(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return array_values(PostModel::query()
            ->whereKey(array_map(static fn (PostId $id): string => $id->value, $ids))
            ->get()
            ->map(fn (PostModel $model): Post => $this->toEntity($model))
            ->all());
    }

    public function highestNumber(DiscussionId $discussionId): ?PostNumber
    {
        $highest = PostModel::query()->where('discussion_id', $discussionId->value)->max('number');

        return is_numeric($highest) ? new PostNumber((int) $highest) : null;
    }

    public function inDiscussion(DiscussionId $discussionId): array
    {
        return array_values(PostModel::query()
            ->where('discussion_id', $discussionId->value)
            // Pending posts (no number yet) last, in both engines
            ->orderByRaw('CASE WHEN number IS NULL THEN 1 ELSE 0 END')
            ->orderBy('number')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->map(fn (PostModel $model): Post => $this->toEntity($model))
            ->all());
    }

    public function delete(PostId $id): void
    {
        PostModel::query()->whereKey($id->value)->delete();
    }

    private function toEntity(PostModel $model): Post
    {
        return new Post(
            id: PostId::fromString($model->id),
            discussionId: DiscussionId::fromString($model->discussion_id),
            number: $model->number === null ? null : new PostNumber($model->number),
            authorId: $model->author_id,
            body: new PostBody($model->body, $model->plain_text),
            status: PostStatus::from($model->status),
            createdAt: $model->created_at->toDateTimeImmutable(),
            publishedAt: $model->published_at?->toDateTimeImmutable(),
            editedAt: $model->edited_at?->toDateTimeImmutable(),
        );
    }
}
