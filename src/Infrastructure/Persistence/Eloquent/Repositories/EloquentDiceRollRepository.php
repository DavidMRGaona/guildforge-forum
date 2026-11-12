<?php

declare(strict_types=1);

namespace Modules\Forum\Infrastructure\Persistence\Eloquent\Repositories;

use Carbon\CarbonImmutable;
use Modules\Forum\Domain\Dice\RollResult;
use Modules\Forum\Domain\Entities\DiceRoll;
use Modules\Forum\Domain\Repositories\DiceRollRepositoryInterface;
use Modules\Forum\Domain\ValueObjects\PostId;
use Modules\Forum\Infrastructure\Persistence\Eloquent\Models\DiceRollModel;

final class EloquentDiceRollRepository implements DiceRollRepositoryInterface
{
    public function save(DiceRoll $roll): void
    {
        $stored = DiceRollModel::query()->find($roll->id);

        if ($stored instanceof DiceRollModel) {
            // What was thrown never changes: only where it sits and whether it was taken out
            $stored->position = $roll->position;
            $stored->removed_at = $roll->removedAt === null ? null : CarbonImmutable::instance($roll->removedAt);
            $stored->save();

            return;
        }

        $model = new DiceRollModel([
            'post_id' => $roll->postId->value,
            'position' => $roll->position,
            'expression' => $roll->expression,
            'dice_count' => $roll->diceCount,
            'result' => $roll->result?->toArray(),
            'total' => $roll->result?->total,
            'error' => $roll->error,
            'added_on_edit' => $roll->addedOnEdit,
            'rolled_at' => $roll->rolledAt,
            'removed_at' => $roll->removedAt,
        ]);
        $model->id = $roll->id;
        $model->save();
    }

    public function ofPost(PostId $postId): array
    {
        return array_values(DiceRollModel::query()
            ->where('post_id', $postId->value)
            ->orderBy('rolled_at')
            ->orderBy('position')
            ->get()
            ->map($this->toEntity(...))
            ->all());
    }

    public function findMany(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return array_values(DiceRollModel::query()
            ->whereIn('id', $ids)
            ->get()
            ->map($this->toEntity(...))
            ->all());
    }

    public function deleteOfPost(PostId $postId): void
    {
        DiceRollModel::query()->where('post_id', $postId->value)->delete();
    }

    private function toEntity(DiceRollModel $model): DiceRoll
    {
        return DiceRoll::restore(
            id: $model->id,
            postId: PostId::fromString($model->post_id),
            position: $model->position,
            expression: $model->expression,
            diceCount: $model->dice_count,
            result: RollResult::fromArray($model->result),
            error: $model->error,
            addedOnEdit: $model->added_on_edit,
            rolledAt: $model->rolled_at->toDateTimeImmutable(),
            removedAt: $model->removed_at?->toDateTimeImmutable(),
        );
    }
}
