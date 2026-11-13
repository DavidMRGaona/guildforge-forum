<?php

declare(strict_types=1);

namespace Modules\Forum\Infrastructure\Services;

use App\Application\Mail\Services\MailSubjectMuteServiceInterface;
use App\Infrastructure\Authorization\Services\PermissionHolderFinder;
use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Modules\Forum\Application\Contracts\ConversationNoticesInterface;
use Modules\Forum\Application\Contracts\ConversationParticipantsInterface;
use Modules\Forum\Application\Services\ForumMailSubjects;
use Modules\Forum\Domain\Repositories\ConversationRepositoryInterface;
use Modules\Forum\Domain\ValueObjects\ConversationId;
use Modules\Forum\Domain\ValueObjects\ConversationMessageId;
use Modules\Forum\Domain\ValueObjects\ConversationReportId;
use Modules\Forum\Notifications\ConversationAddedNotification;
use Modules\Forum\Notifications\ConversationMessageNotification;
use Modules\Forum\Notifications\ConversationReportedNotification;
use Throwable;

/**
 * Notices of private conversations, queued after the commit (the notifications are queued and
 * wait for it themselves). Each one checks again when delivered who may still receive it.
 */
final readonly class QueuedConversationNotices implements ConversationNoticesInterface
{
    private const string MODERATE_FORUM = 'forum:forum.moderate';

    public function __construct(
        private ConversationRepositoryInterface $conversations,
        private ConversationParticipantsInterface $participants,
        private PermissionHolderFinder $holders,
        private MailSubjectMuteServiceInterface $mutes,
    ) {}

    public function messageWritten(ConversationId $conversationId, ConversationMessageId $messageId): void
    {
        $authorId = $this->conversations->findMessage($messageId)?->authorId();
        if ($authorId === null) {
            return;
        }

        $recipients = array_values(array_diff($this->participants->of($conversationId), [$authorId]));
        $this->send($recipients, new ConversationMessageNotification($conversationId->value, $authorId, $messageId->value));
    }

    public function added(ConversationId $conversationId, string $addedId, string $adderId): void
    {
        $this->send([strtolower($addedId)], new ConversationAddedNotification($conversationId->value, strtolower($adderId)));
    }

    public function reported(ConversationReportId $reportId): void
    {
        $notification = new ConversationReportedNotification($reportId->value);
        DB::afterCommit(function () use ($notification): void {
            $moderators = $this->holders->usersWhoCan(self::MODERATE_FORUM);
            if ($moderators->isNotEmpty()) {
                Notification::send($moderators, $notification);
            }
        });
    }

    public function forgetConversation(ConversationId $conversationId): void
    {
        DB::afterCommit(function () use ($conversationId): void {
            try {
                $this->mutes->forgetSubject(ForumMailSubjects::CONVERSATION, $conversationId->value);
            } catch (Throwable $exception) {
                Log::warning('The forum could not forget the mutes and notices of a conversation that went.', ['id' => $conversationId->value, 'exception' => $exception->getMessage()]);
            }
        });
    }

    /**
     * @param  list<string>  $accountIds
     */
    private function send(array $accountIds, object $notification): void
    {
        if ($accountIds === []) {
            return;
        }

        DB::afterCommit(static function () use ($accountIds, $notification): void {
            $users = UserModel::query()->whereKey($accountIds)->get();
            if ($users->isNotEmpty()) {
                Notification::send($users, $notification);
            }
        });
    }
}
