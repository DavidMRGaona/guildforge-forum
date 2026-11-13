<?php

declare(strict_types=1);

namespace Modules\Forum\Infrastructure\Persistence\Eloquent\Repositories;

use Modules\Forum\Domain\Entities\Conversation;
use Modules\Forum\Domain\Entities\ConversationMessage;
use Modules\Forum\Domain\Enums\ConversationEntryKind;
use Modules\Forum\Domain\Repositories\ConversationRepositoryInterface;
use Modules\Forum\Domain\ValueObjects\ConversationId;
use Modules\Forum\Domain\ValueObjects\ConversationMessageId;
use Modules\Forum\Domain\ValueObjects\ConversationTitle;
use Modules\Forum\Domain\ValueObjects\PostBody;
use Modules\Forum\Infrastructure\Persistence\Eloquent\Models\ConversationMessageModel;
use Modules\Forum\Infrastructure\Persistence\Eloquent\Models\ConversationModel;

final readonly class EloquentConversationRepository implements ConversationRepositoryInterface
{
    public function find(ConversationId $id): ?Conversation
    {
        $model = ConversationModel::query()->find($id->value);

        return $model instanceof ConversationModel ? $this->toEntity($model) : null;
    }

    public function lock(ConversationId $id): ?Conversation
    {
        $model = ConversationModel::query()->whereKey($id->value)->lockForUpdate()->first();

        return $model instanceof ConversationModel ? $this->toEntity($model) : null;
    }

    public function save(Conversation $conversation): void
    {
        ConversationModel::query()->updateOrCreate(['id' => $conversation->id()->value], [
            'title' => $conversation->title()->value,
            'last_number' => $conversation->lastNumber(),
            'last_message_number' => $conversation->lastMessageNumber(),
            'last_activity_at' => $conversation->lastActivityAt(),
            'created_at' => $conversation->createdAt(),
        ]);
    }

    public function delete(ConversationId $id): void
    {
        // Through the model, with the foreign keys taking entries and participants along
        ConversationModel::query()->whereKey($id->value)->first()?->delete();
    }

    public function saveMessage(ConversationMessage $message): void
    {
        ConversationMessageModel::query()->updateOrCreate(['id' => $message->id()->value], [
            'conversation_id' => $message->conversationId()->value,
            'number' => $message->number(),
            'kind' => $message->kind()->value,
            'author_id' => $message->authorId(),
            'subject_user_id' => $message->subjectUserId(),
            'body' => $message->isShown() ? $message->body()->document : null,
            'plain_text' => $message->isShown() ? $message->body()->plainText : null,
            'created_at' => $message->createdAt(),
            'deleted_at' => $message->deletedAt(),
            'hidden_at' => $message->hiddenAt(),
            'hidden_reason' => $message->hiddenReason(),
        ]);
    }

    public function findMessage(ConversationMessageId $id): ?ConversationMessage
    {
        $model = ConversationMessageModel::query()->find($id->value);

        return $model instanceof ConversationMessageModel ? $this->toMessage($model) : null;
    }

    public function entries(ConversationId $id, int $from, int $to): array
    {
        if ($to < $from) {
            return [];
        }

        return array_values(ConversationMessageModel::query()
            ->where('conversation_id', $id->value)
            ->whereBetween('number', [$from, $to])
            ->orderBy('number')
            ->get()
            ->map($this->toMessage(...))
            ->all());
    }

    public function around(ConversationId $id, int $number, int $before, int $after): array
    {
        $messages = ConversationMessageModel::query()
            ->where('conversation_id', $id->value)
            ->where('kind', ConversationEntryKind::Message->value);

        $first = (clone $messages)->where('number', '<', $number)->orderByDesc('number')->skip(max(0, $before - 1))->value('number');
        $last = (clone $messages)->where('number', '>', $number)->orderBy('number')->skip(max(0, $after - 1))->value('number');

        $from = $before === 0 ? $number : (is_numeric($first) ? (int) $first : 1);
        $query = ConversationMessageModel::query()->where('conversation_id', $id->value)->where('number', '>=', $from);
        if ($after === 0) {
            $query->where('number', '<=', $number);
        } elseif (is_numeric($last)) {
            $query->where('number', '<=', (int) $last);
        }

        return array_values($query->orderBy('number')->get()->map($this->toMessage(...))->all());
    }

    private function toEntity(ConversationModel $model): Conversation
    {
        return new Conversation(
            id: ConversationId::fromString($model->id),
            title: new ConversationTitle($model->title),
            createdAt: ($model->created_at ?? $model->last_activity_at)->toDateTimeImmutable(),
            lastNumber: $model->last_number,
            lastMessageNumber: $model->last_message_number,
            lastActivityAt: $model->last_activity_at->toDateTimeImmutable(),
        );
    }

    private function toMessage(ConversationMessageModel $model): ConversationMessage
    {
        $body = $model->body !== null && ($model->body['type'] ?? null) === 'doc'
            ? new PostBody($model->body, $model->plain_text ?? '')
            : PostBody::empty();

        return new ConversationMessage(
            id: ConversationMessageId::fromString($model->id),
            conversationId: ConversationId::fromString($model->conversation_id),
            number: $model->number,
            kind: ConversationEntryKind::from($model->kind),
            authorId: $model->author_id,
            body: $body,
            createdAt: $model->created_at->toDateTimeImmutable(),
            subjectUserId: $model->subject_user_id,
            deletedAt: $model->deleted_at?->toDateTimeImmutable(),
            hiddenAt: $model->hidden_at?->toDateTimeImmutable(),
            hiddenReason: $model->hidden_reason,
        );
    }
}
