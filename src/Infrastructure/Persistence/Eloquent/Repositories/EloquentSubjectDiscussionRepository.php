<?php

declare(strict_types=1);

namespace Modules\Forum\Infrastructure\Persistence\Eloquent\Repositories;

use App\Application\Services\TimezoneServiceInterface;
use Illuminate\Database\Eloquent\Builder;
use Modules\Forum\Domain\Entities\SubjectDiscussion;
use Modules\Forum\Domain\Repositories\SubjectDiscussionRepositoryInterface;
use Modules\Forum\Domain\ValueObjects\DiscussionId;
use Modules\Forum\Domain\ValueObjects\PostId;
use Modules\Forum\Domain\ValueObjects\PublicationKey;
use Modules\Forum\Infrastructure\Persistence\Eloquent\Models\SubjectDiscussionModel;
use RuntimeException;

final readonly class EloquentSubjectDiscussionRepository implements SubjectDiscussionRepositoryInterface
{
    public function __construct(
        private TimezoneServiceInterface $timezone,
    ) {}

    public function lockOrCreate(PublicationKey $key): SubjectDiscussion
    {
        // Bindings bypass the model's storage-zone cast, so the time is converted by hand
        $now = $this->timezone->toStorage(now())->format('Y-m-d H:i:s');
        SubjectDiscussionModel::query()->insertOrIgnore([
            'subject_type' => $key->type,
            'subject_id' => $key->id,
            'is_live' => false,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $model = $this->query($key)->lockForUpdate()->first();

        return $model instanceof SubjectDiscussionModel
            ? $this->toEntity($model)
            : throw new RuntimeException("The record of publication {$key->value()} could not be read back.");
    }

    public function find(PublicationKey $key): ?SubjectDiscussion
    {
        $model = $this->query($key)->first();

        return $model instanceof SubjectDiscussionModel ? $this->toEntity($model) : null;
    }

    public function findByDiscussion(DiscussionId $discussionId): ?SubjectDiscussion
    {
        $model = SubjectDiscussionModel::query()->where('discussion_id', $discussionId->value)->first();

        return $model instanceof SubjectDiscussionModel ? $this->toEntity($model) : null;
    }

    public function save(SubjectDiscussion $record): void
    {
        $key = $record->key();
        $model = $this->query($key)->first() ?? new SubjectDiscussionModel(['subject_type' => $key->type, 'subject_id' => $key->id]);

        $model->fill([
            'discussion_id' => $record->discussionId()?->value,
            'first_post_id' => $record->firstPostId()?->value,
            'facts' => $record->facts(),
            'is_live' => $record->isLive(),
            'next_check_at' => $record->nextCheckAt(),
            'space_open' => $record->spaceOpen(),
        ]);
        $model->save();
    }

    public function liveIds(string $type): iterable
    {
        $query = SubjectDiscussionModel::query()
            ->select(['subject_id'])
            ->where('subject_type', $type)
            ->where('is_live', true)
            ->whereNotNull('discussion_id')
            ->orderBy('subject_id');

        foreach ($query->cursor() as $model) {
            yield $model->subject_id;
        }
    }

    /**
     * @return Builder<SubjectDiscussionModel>
     */
    private function query(PublicationKey $key): Builder
    {
        return SubjectDiscussionModel::query()->where('subject_type', $key->type)->where('subject_id', $key->id);
    }

    private function toEntity(SubjectDiscussionModel $model): SubjectDiscussion
    {
        $facts = $model->facts;

        return new SubjectDiscussion(
            new PublicationKey($model->subject_type, $model->subject_id),
            $model->discussion_id === null ? null : DiscussionId::fromString($model->discussion_id),
            $model->first_post_id === null ? null : PostId::fromString($model->first_post_id),
            is_array($facts) ? $this->scalars($facts) : null,
            $model->is_live,
            $model->next_check_at,
            $model->space_open,
        );
    }

    /**
     * @param  array<mixed>  $facts
     * @return array<string, scalar|null>
     */
    private function scalars(array $facts): array
    {
        $clean = [];
        foreach ($facts as $name => $value) {
            if (is_string($name) && ($value === null || is_scalar($value))) {
                $clean[$name] = $value;
            }
        }

        return $clean;
    }
}
