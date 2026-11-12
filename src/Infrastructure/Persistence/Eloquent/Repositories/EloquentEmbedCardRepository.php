<?php

declare(strict_types=1);

namespace Modules\Forum\Infrastructure\Persistence\Eloquent\Repositories;

use Illuminate\Database\Eloquent\Builder;
use Modules\Forum\Domain\Entities\EmbedCard;
use Modules\Forum\Domain\Repositories\EmbedCardRepositoryInterface;
use Modules\Forum\Infrastructure\Persistence\Eloquent\Models\EmbedCardModel;

final readonly class EloquentEmbedCardRepository implements EmbedCardRepositoryInterface
{
    public function find(string $service, string $embedId): ?EmbedCard
    {
        $model = EmbedCardModel::query()->where('service', $service)->where('embed_id', $embedId)->first();

        return $model instanceof EmbedCardModel ? $this->toEntity($model) : null;
    }

    public function findMany(array $embeds): array
    {
        if ($embeds === []) {
            return [];
        }

        $cards = [];
        foreach (array_chunk($embeds, 200) as $chunk) {
            $query = EmbedCardModel::query()->where(static function (Builder $query) use ($chunk): void {
                foreach ($chunk as [$service, $embedId]) {
                    $query->orWhere(static fn (Builder $one): Builder => $one->where('service', $service)->where('embed_id', $embedId));
                }
            });
            foreach ($query->get() as $model) {
                $cards[] = $this->toEntity($model);
            }
        }

        return $cards;
    }

    public function save(EmbedCard $card): void
    {
        $model = EmbedCardModel::query()->firstOrNew(['service' => $card->service, 'embed_id' => $card->embedId]);
        $model->fill([
            'available' => $card->available,
            'title' => $card->title,
            'author_name' => $card->authorName,
            'thumbnail_path' => $card->thumbnailPath,
            'details' => $card->details === [] ? null : $card->details,
            'fetched_at' => $card->fetchedAt,
        ]);
        $model->save();
    }

    public function withThumbnails(): array
    {
        return array_values(EmbedCardModel::query()->whereNotNull('thumbnail_path')->get()->map($this->toEntity(...))->all());
    }

    private function toEntity(EmbedCardModel $model): EmbedCard
    {
        $details = [];
        foreach ($model->details ?? [] as $key => $value) {
            if (is_string($value)) {
                $details[$key] = $value;
            }
        }

        return new EmbedCard($model->service, $model->embed_id, $model->available, $model->title, $model->author_name, $model->thumbnail_path, $details, $model->fetched_at);
    }
}
