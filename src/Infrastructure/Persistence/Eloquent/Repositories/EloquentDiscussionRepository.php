<?php

declare(strict_types=1);

namespace Modules\Forum\Infrastructure\Persistence\Eloquent\Repositories;

use Modules\Forum\Domain\Entities\Discussion;
use Modules\Forum\Domain\Enums\DiscussionStatus;
use Modules\Forum\Domain\Exceptions\DiscussionNotFoundException;
use Modules\Forum\Domain\Repositories\DiscussionRepositoryInterface;
use Modules\Forum\Domain\ValueObjects\CategoryId;
use Modules\Forum\Domain\ValueObjects\DiscussionId;
use Modules\Forum\Domain\ValueObjects\DiscussionTitle;
use Modules\Forum\Infrastructure\Persistence\Eloquent\Models\DiscussionModel;

final class EloquentDiscussionRepository implements DiscussionRepositoryInterface
{
    public function save(Discussion $discussion): void
    {
        DiscussionModel::query()->updateOrCreate(['id' => $discussion->id()->value], [
            'category_id' => $discussion->categoryId()->value,
            'author_id' => $discussion->authorId(),
            'title' => $discussion->title()->value,
            'status' => $discussion->status()->value,
            'is_pinned' => $discussion->isPinned(),
            'is_hidden' => $discussion->isHidden(),
            'created_at' => $discussion->createdAt(),
        ]);
    }

    public function find(DiscussionId $id): ?Discussion
    {
        $model = DiscussionModel::query()->find($id->value);

        return $model instanceof DiscussionModel ? $this->toEntity($model) : null;
    }

    public function findOrFail(DiscussionId $id): Discussion
    {
        return $this->find($id) ?? throw DiscussionNotFoundException::withId($id->value);
    }

    public function findForUpdate(DiscussionId $id): Discussion
    {
        $model = DiscussionModel::query()->whereKey($id->value)->lockForUpdate()->first();

        return $model instanceof DiscussionModel ? $this->toEntity($model) : throw DiscussionNotFoundException::withId($id->value);
    }

    public function delete(DiscussionId $id): void
    {
        DiscussionModel::query()->whereKey($id->value)->delete();
    }

    private function toEntity(DiscussionModel $model): Discussion
    {
        return new Discussion(
            id: DiscussionId::fromString($model->id),
            categoryId: CategoryId::fromString($model->category_id),
            authorId: $model->author_id,
            title: new DiscussionTitle($model->title),
            status: DiscussionStatus::from($model->status),
            pinned: $model->is_pinned,
            hidden: $model->is_hidden,
            createdAt: $model->created_at->toDateTimeImmutable(),
        );
    }
}
