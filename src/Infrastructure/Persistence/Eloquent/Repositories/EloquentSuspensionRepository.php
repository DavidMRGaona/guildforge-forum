<?php

declare(strict_types=1);

namespace Modules\Forum\Infrastructure\Persistence\Eloquent\Repositories;

use App\Application\Services\TimezoneServiceInterface;
use DateTimeImmutable;
use Illuminate\Database\Eloquent\Builder;
use Modules\Forum\Domain\Entities\Suspension;
use Modules\Forum\Domain\Repositories\SuspensionRepositoryInterface;
use Modules\Forum\Domain\ValueObjects\SuspensionId;
use Modules\Forum\Infrastructure\Persistence\Eloquent\Models\SuspensionModel;

final readonly class EloquentSuspensionRepository implements SuspensionRepositoryInterface
{
    public function __construct(
        private TimezoneServiceInterface $timezone,
    ) {}

    public function save(Suspension $suspension): void
    {
        SuspensionModel::query()->updateOrCreate(['id' => $suspension->id()->value], [
            'user_id' => $suspension->userId(),
            'suspended_by' => $suspension->suspendedBy(),
            'reason' => $suspension->reason(),
            'starts_at' => $suspension->startsAt(),
            'ends_at' => $suspension->endsAt(),
            'lifted_at' => $suspension->liftedAt(),
            'lifted_by' => $suspension->liftedBy(),
        ]);
    }

    public function find(SuspensionId $id): ?Suspension
    {
        $model = SuspensionModel::query()->find($id->value);

        return $model instanceof SuspensionModel ? $this->toEntity($model) : null;
    }

    public function activeFor(string $userId, DateTimeImmutable $at): ?Suspension
    {
        // Bindings skip the models' zone handling: the bound goes in the storage zone
        $bound = $this->timezone->toStorage($at);

        $model = SuspensionModel::query()
            ->where('user_id', strtolower($userId))
            ->whereNull('lifted_at')
            ->where('starts_at', '<=', $bound)
            ->where(static fn (Builder $query): Builder => $query->whereNull('ends_at')->orWhere('ends_at', '>', $bound))
            // One with no end first, then the one that ends last
            ->orderByRaw('CASE WHEN ends_at IS NULL THEN 0 ELSE 1 END')
            ->orderByDesc('ends_at')
            ->first();

        return $model instanceof SuspensionModel ? $this->toEntity($model) : null;
    }

    private function toEntity(SuspensionModel $model): Suspension
    {
        return new Suspension(
            id: SuspensionId::fromString($model->id),
            userId: $model->user_id,
            suspendedBy: $model->suspended_by,
            reason: $model->reason,
            startsAt: $model->starts_at->toDateTimeImmutable(),
            endsAt: $model->ends_at?->toDateTimeImmutable(),
            liftedAt: $model->lifted_at?->toDateTimeImmutable(),
            liftedBy: $model->lifted_by,
        );
    }
}
