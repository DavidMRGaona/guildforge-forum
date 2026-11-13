<?php

declare(strict_types=1);

namespace Modules\Forum\Application\Conversations;

use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use Modules\Forum\Application\Contracts\ConversationNoticesInterface;
use Modules\Forum\Application\Contracts\ConversationParticipantsInterface;
use Modules\Forum\Application\Contracts\ConversationStartsInterface;
use Modules\Forum\Application\Contracts\ForumLiveSignalsInterface;
use Modules\Forum\Application\Contracts\PostRateLimiterInterface;
use Modules\Forum\Application\Services\ForumBrowser;
use Modules\Forum\Domain\ConversationRules;
use Modules\Forum\Domain\Entities\Conversation;
use Modules\Forum\Domain\Entities\ConversationMessage;
use Modules\Forum\Domain\Enums\ConversationEntryKind;
use Modules\Forum\Domain\Exceptions\ConversationNotFoundException;
use Modules\Forum\Domain\Exceptions\ConversationRefusedException;
use Modules\Forum\Domain\Exceptions\InvalidConversationException;
use Modules\Forum\Domain\Exceptions\InvalidDocumentException;
use Modules\Forum\Domain\Exceptions\PostingTooFastException;
use Modules\Forum\Domain\Repositories\ConversationRepositoryInterface;
use Modules\Forum\Domain\ValueObjects\AccountId;
use Modules\Forum\Domain\ValueObjects\ConversationId;
use Modules\Forum\Domain\ValueObjects\ConversationMessageId;
use Modules\Forum\Domain\ValueObjects\ConversationTitle;

/**
 * Starting private conversations, writing in them, adding people and leaving (forum#18).
 *
 * - From 2 to 10 people, all with an active account that is not suspended in the forum.
 * - At most 5 new conversations per person in 24 hours; replying does not count. Every message
 *   also counts against the forum's posts per minute.
 * - Anyone in the conversation adds someone else, while it has room; anyone leaves when they
 *   want. Whoever is added reads it from the start, and their unread count starts with them.
 *   When the last person leaves, the conversation goes.
 * - A block keeps the blocked person from writing to, starting with or adding the one who
 *   blocked them, and from adding anyone to a conversation the blocker is in (the note of who
 *   was added is written to everyone). It says so in the same words as anything else that
 *   cannot be done.
 *
 * Every change runs with the conversation locked, and its notices go after the commit.
 */
final readonly class ConversationService
{
    public function __construct(
        private ConversationRepositoryInterface $conversations,
        private ConversationParticipantsInterface $participants,
        private ConversationStartsInterface $starts,
        private ConversationAccess $access,
        private ConversationDocuments $documents,
        private PostRateLimiterInterface $rateLimiter,
        private ConversationNoticesInterface $notices,
        private ForumLiveSignalsInterface $live,
    ) {}

    /**
     * The conversation, for whoever is in it.
     *
     * @throws ConversationNotFoundException for anyone else, and for one that does not exist
     */
    public function readable(?Authenticatable $user, ConversationId $id): Conversation
    {
        $conversation = $this->access->canRead($user, $id) ? $this->conversations->find($id) : null;

        return $conversation instanceof Conversation ? $conversation : throw ConversationNotFoundException::withId($id->value);
    }

    /**
     * How many conversations the person may still start now.
     */
    public function startsLeft(Authenticatable $user): int
    {
        $accountId = (string) ForumBrowser::accountId($user);

        return max(0, ConversationRules::STARTS_PER_DAY - $this->starts->countSince($accountId, $this->windowStart()));
    }

    /**
     * Starts a conversation with the people chosen and its first message.
     *
     * @param  list<string>  $recipientIds  the others; the author is added on top
     *
     * @throws ConversationRefusedException
     * @throws InvalidConversationException for a blank or too long title
     * @throws InvalidDocumentException
     * @throws PostingTooFastException
     */
    public function start(Authenticatable $author, string $title, array $recipientIds, mixed $document): Conversation
    {
        $authorId = $this->writer($author);
        $title = new ConversationTitle($title);
        $body = $this->documents->fromInput($document);

        $recipients = [];
        foreach ($recipientIds as $id) {
            $normalized = AccountId::normalize($id);
            if ($normalized === null || $normalized === $authorId) {
                throw ConversationRefusedException::because(ConversationRefusedException::UNAVAILABLE_PEOPLE);
            }
            $recipients[$normalized] = true;
        }
        $recipients = array_keys($recipients);

        $count = count($recipients) + 1;
        if ($count < ConversationRules::MIN_PARTICIPANTS || $count > ConversationRules::MAX_PARTICIPANTS) {
            throw ConversationRefusedException::because(ConversationRefusedException::PARTICIPANTS);
        }

        foreach ($recipients as $recipient) {
            if (! $this->access->mayTakePart($recipient) || $this->access->isBlockedBy($authorId, $recipient)) {
                throw ConversationRefusedException::because(ConversationRefusedException::UNAVAILABLE_PEOPLE);
            }
        }

        if ($this->startsLeft($author) === 0) {
            throw ConversationRefusedException::because(ConversationRefusedException::DAILY_LIMIT);
        }
        $this->countMessage($authorId);

        $now = CarbonImmutable::now()->toDateTimeImmutable();
        $conversation = Conversation::start(ConversationId::generate(), $title, $now);
        $message = null;

        DB::transaction(function () use ($authorId, $recipients, $conversation, $body, $now, &$message): void {
            // Two starts at once by one person wait for each other here, so the limit holds
            $this->starts->lockFor($authorId);
            if ($this->starts->countSince($authorId, $this->windowStart()) >= ConversationRules::STARTS_PER_DAY) {
                throw ConversationRefusedException::because(ConversationRefusedException::DAILY_LIMIT);
            }

            $number = $conversation->takeNumber(ConversationEntryKind::Message, $now);
            $message = ConversationMessage::message(ConversationMessageId::generate(), $conversation->id(), $number, $authorId, $body, $now);

            $this->conversations->save($conversation);
            $this->participants->add($conversation->id(), $authorId, $number, $now);
            foreach ($recipients as $recipient) {
                $this->participants->add($conversation->id(), $recipient, 0, $now);
            }
            $this->conversations->saveMessage($message);
            $this->starts->record($authorId, $now);
        });

        if ($message instanceof ConversationMessage) {
            $this->notices->messageWritten($conversation->id(), $message->id());
            $this->live->conversationEntryAdded($conversation->id(), $message->number(), $authorId);
        }

        return $conversation;
    }

    /**
     * Writes a message in the conversation.
     *
     * @throws ConversationNotFoundException
     * @throws ConversationRefusedException
     * @throws InvalidDocumentException
     * @throws PostingTooFastException
     */
    public function reply(Authenticatable $author, ConversationId $id, mixed $document): ConversationMessage
    {
        $authorId = (string) ForumBrowser::accountId($author);
        $this->readable($author, $id);
        $body = $this->documents->fromInput($document);

        $message = DB::transaction(function () use ($author, $authorId, $id, $body): ConversationMessage {
            $conversation = $this->locked($author, $id);
            $refusal = $this->access->writeRefusal($author, $conversation);
            if ($refusal !== null) {
                throw ConversationRefusedException::because($refusal);
            }

            $this->countMessage($authorId);

            $now = CarbonImmutable::now()->toDateTimeImmutable();
            $message = ConversationMessage::message(ConversationMessageId::generate(), $id, $conversation->takeNumber(ConversationEntryKind::Message, $now), $authorId, $body, $now);
            $this->conversations->saveMessage($message);
            $this->conversations->save($conversation);
            $this->participants->markRead($id, $authorId, $message->number());

            return $message;
        });

        $this->notices->messageWritten($id, $message->id());
        $this->live->conversationEntryAdded($id, $message->number(), $authorId);

        return $message;
    }

    /**
     * Adds someone to the conversation.
     *
     * @throws ConversationNotFoundException
     * @throws ConversationRefusedException
     */
    public function add(Authenticatable $adder, ConversationId $id, string $accountId): void
    {
        $adderId = $this->writer($adder);
        $this->readable($adder, $id);
        $added = AccountId::normalize($accountId);
        if ($added === null || $added === $adderId || ! $this->access->mayTakePart($added) || $this->access->isBlockedBy($adderId, $added)) {
            throw ConversationRefusedException::because(ConversationRefusedException::UNAVAILABLE_PEOPLE);
        }

        $noteNumber = 0;
        DB::transaction(function () use ($adder, $adderId, $id, $added, &$noteNumber): void {
            $conversation = $this->locked($adder, $id);
            $people = $this->participants->of($id);
            // Adding writes a note everyone reads: whoever may not write there adds nobody. Being
            // left alone is the one refusal that adding someone mends
            $refusal = $this->access->writeRefusal($adder, $conversation, $people);
            if ($refusal !== null && $refusal !== ConversationRefusedException::ALONE) {
                throw ConversationRefusedException::because($refusal);
            }
            if (in_array($added, $people, true)) {
                throw ConversationRefusedException::because(ConversationRefusedException::ALREADY_IN);
            }
            if (count($people) >= ConversationRules::MAX_PARTICIPANTS) {
                throw ConversationRefusedException::because(ConversationRefusedException::FULL);
            }

            $now = CarbonImmutable::now()->toDateTimeImmutable();
            // Read through what was there: the conversation is unread from their arrival on
            $this->participants->add($id, $added, $conversation->lastNumber(), $now);
            $note = ConversationMessage::added(ConversationMessageId::generate(), $id, $conversation->takeNumber(ConversationEntryKind::Added, $now), $adderId, $added, $now);
            $this->conversations->saveMessage($note);
            $this->conversations->save($conversation);
            $noteNumber = $note->number();
        });

        $this->notices->added($id, $added, $adderId);
        $this->live->conversationEntryAdded($id, $noteNumber, $adderId);
    }

    /**
     * The person leaves the conversation; when nobody is left, it goes.
     *
     * @throws ConversationNotFoundException
     */
    public function leave(Authenticatable $user, ConversationId $id): void
    {
        $this->readable($user, $id);
        $this->leaveAs((string) ForumBrowser::accountId($user), $id);
    }

    /**
     * The account leaves the conversation, also when it may not act any more (anonymized).
     * Leaving one it is not in changes nothing.
     */
    public function leaveAs(string $accountId, ConversationId $id): void
    {
        $noteNumber = 0;
        $gone = DB::transaction(function () use ($accountId, $id, &$noteNumber): bool {
            $conversation = $this->conversations->lock($id);
            if (! $conversation instanceof Conversation || ! $this->participants->isIn($id, $accountId)) {
                return false;
            }

            $this->participants->remove($id, $accountId);
            if ($this->participants->of($id) === []) {
                $this->conversations->delete($id);

                return true;
            }

            $now = CarbonImmutable::now()->toDateTimeImmutable();
            $note = ConversationMessage::left(ConversationMessageId::generate(), $id, $conversation->takeNumber(ConversationEntryKind::Left, $now), $accountId, $now);
            $this->conversations->saveMessage($note);
            $this->conversations->save($conversation);
            $noteNumber = $note->number();

            return false;
        });

        if ($gone) {
            $this->notices->forgetConversation($id);
        } elseif ($noteNumber > 0) {
            $this->live->conversationEntryAdded($id, $noteNumber, $accountId);
        }
    }

    /**
     * The author deletes their message: «Mensaje borrado» stays in its place.
     *
     * @throws ConversationNotFoundException when it is not in a conversation the person is in
     * @throws ConversationRefusedException when it is not theirs, or not a message shown
     */
    public function deleteOwn(Authenticatable $user, ConversationMessageId $messageId): ConversationMessage
    {
        $message = $this->conversations->findMessage($messageId);
        if (! $message instanceof ConversationMessage || ! $this->access->canRead($user, $message->conversationId())) {
            throw ConversationNotFoundException::withId($messageId->value);
        }
        if ($message->authorId() !== ForumBrowser::accountId($user) || ! $message->isShown()) {
            throw ConversationRefusedException::because(ConversationRefusedException::NOT_ALLOWED);
        }

        $message->deleteByAuthor(CarbonImmutable::now()->toDateTimeImmutable());
        $this->conversations->saveMessage($message);
        $this->live->conversationEntryChanged($message->conversationId(), $message->number());

        return $message;
    }

    /**
     * Marks the conversation read through the entry (never further than it goes).
     *
     * @throws ConversationNotFoundException
     */
    public function markRead(Authenticatable $user, ConversationId $id, int $number): void
    {
        $conversation = $this->readable($user, $id);
        $accountId = (string) ForumBrowser::accountId($user);
        $through = min(max(0, $number), $conversation->lastNumber());
        // The menu counts conversations with something unread: only reaching the end changes that
        $before = $this->participants->readThrough($id, $accountId);
        $this->participants->markRead($id, $accountId, $through);
        if ($before !== null && $before < $conversation->lastNumber() && $through >= $conversation->lastNumber()) {
            $this->live->pulseChanged($accountId);
        }
    }

    /**
     * The id of whoever writes, refused when they are barred.
     *
     * @throws ConversationRefusedException
     */
    private function writer(Authenticatable $user): string
    {
        $accountId = ForumBrowser::accountId($user);
        if ($accountId === null || ! $this->access->mayTakePart($accountId)) {
            throw ConversationRefusedException::because(ConversationRefusedException::BARRED);
        }

        return $accountId;
    }

    /**
     * @throws ConversationNotFoundException when it went, or the person left it, meanwhile
     */
    private function locked(Authenticatable $user, ConversationId $id): Conversation
    {
        $conversation = $this->conversations->lock($id);
        if (! $conversation instanceof Conversation || ! $this->access->canRead($user, $id)) {
            throw ConversationNotFoundException::withId($id->value);
        }

        return $conversation;
    }

    /**
     * @throws PostingTooFastException
     */
    private function countMessage(string $accountId): void
    {
        $wait = $this->rateLimiter->attempt($accountId);
        if ($wait !== null) {
            throw PostingTooFastException::retryIn($wait);
        }
    }

    private function windowStart(): DateTimeImmutable
    {
        return CarbonImmutable::now()->subHours(ConversationRules::STARTS_WINDOW_HOURS)->toDateTimeImmutable();
    }
}
