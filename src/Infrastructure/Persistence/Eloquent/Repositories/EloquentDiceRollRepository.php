<?php

declare(strict_types=1);

namespace Modules\Forum\Infrastructure\Persistence\Eloquent\Repositories;

use App\Application\Services\TimezoneServiceInterface;
use Carbon\CarbonImmutable;
use Modules\Forum\Domain\Dice\RollResult;
use Modules\Forum\Domain\Entities\DiceRoll;
use Modules\Forum\Domain\Repositories\DiceRollRepositoryInterface;
use Modules\Forum\Domain\ValueObjects\PostId;
use Modules\Forum\Infrastructure\Persistence\Eloquent\Models\DiceRollModel;

final readonly class EloquentDiceRollRepository implements DiceRollRepositoryInterface
{
    public function __construct(
        private TimezoneServiceInterface $timezone,
    ) {}

    public function save(DiceRoll $roll): void
    {
        $stored = DiceRollModel::query()->find($roll->id);

        if ($stored instanceof DiceRollModel) {
            // What was thrown never changes: only where it sits and whether it was taken out.
            // Revealing goes through markRevealed(), so a stale copy never hides a roll again
            $stored->position = $roll->position;
            $stored->removed_at = $roll->removedAt instanceof \DateTimeImmutable ? CarbonImmutable::instance($roll->removedAt) : null;
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
            'is_hidden' => $roll->hidden,
            'rolled_by' => $roll->rolledBy,
            'revealed_at' => $roll->revealedAt,
            'revealed_by' => $roll->revealedBy,
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

    public function markRevealed(string $id, string $accountId, \DateTimeImmutable $at): bool
    {
        // Only a hidden roll still secret and in its post: two people revealing at once reveal it once
        return DiceRollModel::query()
            ->whereKey($id)
            ->where('is_hidden', true)
            ->whereNull('revealed_at')
            ->whereNull('removed_at')
                        // A query update skips the model's casts: the instant goes in the storage zone by hand
            ->update(['revealed_at' => $this->timezone->toStorage($at)->format('Y-m-d H:i:s'), 'revealed_by' => $accountId]) === 1;
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
            hidden: $model->is_hidden,
            rolledBy: $model->rolled_by,
            revealedAt: $model->revealed_at?->toDateTimeImmutable(),
            revealedBy: $model->revealed_by,
        );
    }
}
