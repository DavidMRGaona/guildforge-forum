<?php

declare(strict_types=1);

namespace Modules\Forum\Infrastructure\Persistence\Eloquent\Repositories;

use Illuminate\Support\Facades\DB;
use Modules\Forum\Domain\Entities\Discussion;
use Modules\Forum\Domain\Enums\DiscussionStatus;
use Modules\Forum\Domain\Exceptions\DiscussionNotFoundException;
use Modules\Forum\Domain\Repositories\DiscussionRepositoryInterface;
use Modules\Forum\Domain\ValueObjects\CategoryId;
use Modules\Forum\Domain\ValueObjects\DiscussionId;
use Modules\Forum\Domain\ValueObjects\DiscussionTitle;
use Modules\Forum\Domain\ValueObjects\DiscussionTopic;
use Modules\Forum\Infrastructure\Persistence\Eloquent\Models\DiscussionModel;
use Modules\Forum\Infrastructure\Persistence\Eloquent\Models\DiscussionTopicModel;

final class EloquentDiscussionRepository implements DiscussionRepositoryInterface
{
    public function save(Discussion $discussion): void
    {
        DiscussionModel::query()->updateOrCreate(['id' => $discussion->id()->value], [
            'number' => $discussion->number(),
            'category_id' => $discussion->categoryId()->value,
            'author_id' => $discussion->authorId(),
            'title' => $discussion->title()->value,
            'status' => $discussion->status()->value,
            'is_pinned' => $discussion->isPinned(),
            'is_hidden' => $discussion->isHidden(),
            'closed_by_system' => $discussion->isClosedBySystem(),
            'awaiting_approval' => $discussion->isAwaitingApproval(),
            'title_by_moderation' => $discussion->isTitleSetByModeration(),
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

    public function findByNumber(int $number): ?Discussion
    {
        $model = DiscussionModel::query()->where('number', $number)->first();

        return $model instanceof DiscussionModel ? $this->toEntity($model) : null;
    }

    public function nextNumber(): int
    {
        return (int) DiscussionModel::query()->max('number') + 1;
    }

    public function findForUpdate(DiscussionId $id): Discussion
    {
        $model = DiscussionModel::query()->whereKey($id->value)->lockForUpdate()->first();

        return $model instanceof DiscussionModel ? $this->toEntity($model) : throw DiscussionNotFoundException::withId($id->value);
    }

    public function delete(DiscussionId $id): void
    {
        // Through the model, so the site search is told
        DiscussionModel::query()->find($id->value)?->delete();
    }

    public function countInCategory(CategoryId $categoryId): int
    {
        return DiscussionModel::query()->where('category_id', $categoryId->value)->count();
    }

    public function moveAllToCategory(CategoryId $from, CategoryId $to): void
    {
        // One by one, so the site search is told which ones changed category
        foreach (DiscussionModel::query()->where('category_id', $from->value)->get() as $discussion) {
            $discussion->update(['category_id' => $to->value]);
        }
    }

    public function replaceTopics(DiscussionId $id, array $topics): void
    {
        DB::transaction(static function () use ($id, $topics): void {
            DiscussionTopicModel::query()->where('discussion_id', $id->value)->delete();

            $rows = [];
            foreach ($topics as $topic) {
                $rows[$topic->key] = ['discussion_id' => $id->value, 'key' => $topic->key, 'label' => $topic->label];
            }

            if ($rows !== []) {
                DiscussionTopicModel::query()->insert(array_values($rows));
            }
        });
    }

    public function topicsOf(DiscussionId $id): array
    {
        return array_values(DiscussionTopicModel::query()
            ->where('discussion_id', $id->value)
            ->orderBy('label')
            ->orderBy('key')
            ->get()
            ->map(static fn (DiscussionTopicModel $model): DiscussionTopic => new DiscussionTopic($model->key, $model->label))
            ->all());
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
            closedBySystem: $model->closed_by_system,
            number: $model->number,
            awaitingApproval: $model->awaiting_approval,
            titleSetByModeration: $model->title_by_moderation,
        );
    }
}
