<?php

declare(strict_types=1);

namespace Modules\Forum\Http\Presenters;

use App\Application\Services\TimezoneServiceInterface;
use DateTimeImmutable;
use Illuminate\Contracts\Auth\Authenticatable;
use Modules\Forum\Application\Contracts\AccountDirectoryInterface;
use Modules\Forum\Application\Conversations\ConversationDocuments;
use Modules\Forum\Application\Queries\ConversationRowDTO;
use Modules\Forum\Application\Services\ForumBrowser;
use Modules\Forum\Domain\Entities\ConversationMessage;
use Modules\Forum\Domain\Entities\ConversationReport;
use Modules\Forum\Domain\Enums\ConversationEntryKind;
use Modules\Forum\Domain\Enums\ReportReason;
use Modules\Forum\Domain\ValueObjects\ConversationMessageId;

/**
 * What the pages of private conversations show, for one reader who is in them. Names are read
 * from the accounts when painting, so an anonymized account shows its anonymous name.
 */
final readonly class ConversationPresenter
{
    /** Names shown in a row of the list before «y N más» */
    private const int NAMES_IN_ROW = 3;

    public function __construct(
        private AccountDirectoryInterface $accounts,
        private ConversationDocuments $documents,
        private TimezoneServiceInterface $timezone,
    ) {}

    /**
     * @param  list<ConversationRowDTO>  $rows
     * @return list<array<string, mixed>>
     */
    public function rows(array $rows, Authenticatable $reader): array
    {
        $me = (string) ForumBrowser::accountId($reader);
        $ids = [];
        foreach ($rows as $row) {
            array_push($ids, ...$row->participantIds);
        }
        $names = $this->accounts->namesOf(array_values(array_unique($ids)));

        return array_map(function (ConversationRowDTO $row) use ($me, $names): array {
            $others = array_values(array_filter($row->participantIds, static fn (string $id): bool => $id !== $me));
            $shown = array_slice($others, 0, self::NAMES_IN_ROW);

            return [
                'id' => $row->id,
                'title' => $row->title,
                'url' => route('forum.conversations.show', ['conversation' => $row->id]),
                'unread' => $row->unread,
                'people' => array_map(static fn (string $id): string => $names[$id] ?? __('forum::messages.notices.someone'), $shown),
                'morePeople' => max(0, count($others) - count($shown)),
                'lastActivityIso' => $this->timezone->iso($row->lastActivityAt),
                'lastActivityLabel' => $this->timezone->format($row->lastActivityAt),
            ];
        }, $rows);
    }

    /**
     * @param  list<string>  $participantIds
     * @param  list<string>  $blockedByReader  the people the reader blocked
     * @return list<array<string, mixed>>
     */
    public function participants(array $participantIds, Authenticatable $reader, array $blockedByReader): array
    {
        $me = (string) ForumBrowser::accountId($reader);
        $names = $this->accounts->namesOf($participantIds);

        return array_map(static fn (string $id): array => [
            'id' => $id,
            'name' => $names[$id] ?? __('forum::messages.notices.someone'),
            'isMe' => $id === $me,
            'blocked' => in_array($id, $blockedByReader, true),
        ], $participantIds);
    }

    /**
     * @param  list<ConversationMessage>  $entries
     * @param  bool  $mayReport  whether the reader reports messages here (in it, and not barred)
     * @return list<array<string, mixed>>
     */
    public function entries(array $entries, Authenticatable $reader, bool $mayReport): array
    {
        $me = (string) ForumBrowser::accountId($reader);
        $ids = [];
        foreach ($entries as $entry) {
            $ids[] = $entry->authorId();
            $ids[] = $entry->subjectUserId();
        }
        $names = $this->accounts->namesOf(array_values(array_unique(array_filter($ids, is_string(...)))));

        return array_map(fn (ConversationMessage $entry): array => $this->entry($entry, $me, $names, $mayReport), $entries);
    }

    /**
     * @return array<string, mixed>
     */
    public function one(ConversationMessage $entry, Authenticatable $reader, bool $mayReport): array
    {
        return $this->entries([$entry], $reader, $mayReport)[0];
    }

    /**
     * A reported message as moderation reads it: the copy and nothing else.
     *
     * @return array<string, mixed>
     */
    public function report(ConversationReport $report): array
    {
        $ids = [$report->messageAuthorId()];
        foreach ($report->context() as $entry) {
            $ids[] = $entry['authorId'];
            $ids[] = $entry['subjectUserId'];
        }
        $names = $this->accounts->namesOf(array_values(array_unique(array_filter($ids, is_string(...)))));
        $someone = __('forum::messages.notices.someone');

        return [
            'id' => $report->id()->value,
            'authorId' => $report->messageAuthorId(),
            'authorName' => $report->messageAuthorId() === null ? $someone : ($names[$report->messageAuthorId()] ?? $someone),
            'reason' => __('forum::messages.reports.reasons.'.$report->reason()->value),
            'note' => $report->note(),
            'reportedLabel' => $this->timezone->format($report->createdAt()),
            'messageGone' => ! $report->messageId() instanceof ConversationMessageId,
            'context' => array_map(fn (array $entry): array => [
                'number' => $entry['number'],
                'kind' => $entry['kind'],
                'authorName' => $entry['authorId'] === null ? $someone : ($names[$entry['authorId']] ?? $someone),
                'subjectName' => $entry['subjectUserId'] === null ? null : ($names[$entry['subjectUserId']] ?? $someone),
                'text' => $entry['text'],
                'reported' => $entry['reported'],
                'atLabel' => $entry['at'] === '' ? '' : $this->timezone->format(new DateTimeImmutable($entry['at'])),
            ], $report->context()),
            'resolveUrl' => route('forum.conversations.reports.resolve', ['report' => $report->id()->value]),
        ];
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function reportReasons(): array
    {
        return array_map(static fn (ReportReason $reason): array => [
            'value' => $reason->value,
            'label' => __('forum::messages.reports.reasons.'.$reason->value),
        ], ReportReason::cases());
    }

    /**
     * @param  array<string, string>  $names
     * @return array<string, mixed>
     */
    private function entry(ConversationMessage $entry, string $me, array $names, bool $mayReport): array
    {
        $someone = __('forum::messages.notices.someone');
        $authorId = $entry->authorId();
        $mine = $authorId !== null && $authorId === $me;
        $state = match (true) {
            ! $entry->isMessage() => 'note',
            $entry->hiddenAt() instanceof DateTimeImmutable => 'hidden',
            $entry->deletedAt() instanceof DateTimeImmutable => 'deleted',
            default => 'shown',
        };

        return [
            'id' => $entry->id()->value,
            'number' => $entry->number(),
            'kind' => $entry->kind()->value,
            'state' => $state,
            'authorId' => $authorId,
            'authorName' => $authorId === null ? $someone : ($names[$authorId] ?? $someone),
            'subjectName' => $entry->kind() === ConversationEntryKind::Added
                ? ($entry->subjectUserId() === null ? $someone : ($names[$entry->subjectUserId()] ?? $someone))
                : null,
            'isMine' => $mine,
            'html' => $state === 'shown' ? $this->documents->toHtml($entry->body()) : null,
            // Only its author learns why moderation hid it
            'hiddenReason' => $state === 'hidden' && $mine ? $entry->hiddenReason() : null,
            'createdIso' => $this->timezone->iso($entry->createdAt()),
            'createdLabel' => $this->timezone->format($entry->createdAt()),
            'deleteUrl' => $state === 'shown' && $mine ? route('forum.conversations.messages.destroy', ['message' => $entry->id()->value]) : null,
            'reportUrl' => $state === 'shown' && ! $mine && $mayReport ? route('forum.conversations.reports.store', ['message' => $entry->id()->value]) : null,
        ];
    }
}
