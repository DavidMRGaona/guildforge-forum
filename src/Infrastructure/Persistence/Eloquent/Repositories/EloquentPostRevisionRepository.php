<?php

declare(strict_types=1);

namespace Modules\Forum\Infrastructure\Persistence\Eloquent\Repositories;

use Modules\Forum\Domain\Entities\PostRevision;
use Modules\Forum\Domain\Repositories\PostRevisionRepositoryInterface;
use Modules\Forum\Domain\ValueObjects\PostBody;
use Modules\Forum\Domain\ValueObjects\PostId;
use Modules\Forum\Infrastructure\Persistence\Eloquent\Models\PostRevisionModel;

final class EloquentPostRevisionRepository implements PostRevisionRepositoryInterface
{
    public function save(PostRevision $revision): void
    {
        PostRevisionModel::query()->create([
            'post_id' => $revision->postId->value,
            'number' => $revision->number,
            'body' => $revision->body->document,
            'plain_text' => $revision->body->plainText,
            'editor_id' => $revision->editorId(),
            'created_at' => $revision->replacedAt,
        ]);
    }

    public function ofPost(PostId $postId): array
    {
        return array_values(PostRevisionModel::query()
            ->where('post_id', $postId->value)
            ->orderBy('number')
            ->get()
            ->map(static fn (PostRevisionModel $model): PostRevision => new PostRevision(
                postId: PostId::fromString($model->post_id),
                number: $model->number,
                body: new PostBody($model->body, $model->plain_text),
                editorId: $model->editor_id,
                replacedAt: $model->created_at->toDateTimeImmutable(),
            ))
            ->all());
    }

    public function nextNumber(PostId $postId): int
    {
        $highest = PostRevisionModel::query()->where('post_id', $postId->value)->max('number');

        return (is_numeric($highest) ? (int) $highest : 0) + 1;
    }

    public function deleteOfPost(PostId $postId): void
    {
        PostRevisionModel::query()->where('post_id', $postId->value)->delete();
    }
}
