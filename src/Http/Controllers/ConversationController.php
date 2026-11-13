<?php

declare(strict_types=1);

namespace Modules\Forum\Http\Controllers;

use App\Application\Seo\DTOs\SeoPageDTO;
use App\Application\Seo\Services\SeoMetadataFactoryInterface;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Forum\Application\Contracts\AccountDirectoryInterface;
use Modules\Forum\Application\Contracts\ConversationParticipantsInterface;
use Modules\Forum\Application\Contracts\UserBlocksInterface;
use Modules\Forum\Application\Conversations\ConversationAccess;
use Modules\Forum\Application\Conversations\ConversationPeople;
use Modules\Forum\Application\Conversations\ConversationReportService;
use Modules\Forum\Application\Conversations\ConversationService;
use Modules\Forum\Application\Queries\ConversationListQueryInterface;
use Modules\Forum\Application\Services\ForumBrowser;
use Modules\Forum\Domain\ConversationRules;
use Modules\Forum\Domain\Exceptions\ConversationNotFoundException;
use Modules\Forum\Domain\Exceptions\ConversationRefusedException;
use Modules\Forum\Domain\Repositories\ConversationRepositoryInterface;
use Modules\Forum\Domain\ValueObjects\AccountId;
use Modules\Forum\Domain\ValueObjects\ConversationId;
use Modules\Forum\Domain\ValueObjects\ConversationTitle;
use Modules\Forum\Http\Presenters\ConversationPresenter;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The pages of private conversations (/foro/conversaciones): the person's list, a new one and
 * one conversation; and their JSON actions: finding people, starting, adding someone, leaving and
 * marking read. Pages are never indexed. A conversation the person is not in answers 404, for
 * everyone, whatever permissions they hold.
 */
final class ConversationController extends Controller
{
    use RespondsWithConversationErrors;

    public const int PER_PAGE = 20;

    /** Entries on one page of a conversation, the latest page first */
    public const int ENTRIES_PER_PAGE = 50;

    public function __construct(
        private readonly ConversationService $service,
        private readonly ConversationAccess $access,
        private readonly ConversationPeople $people,
        private readonly ConversationReportService $reports,
        private readonly ConversationRepositoryInterface $conversations,
        private readonly ConversationParticipantsInterface $participants,
        private readonly ConversationListQueryInterface $list,
        private readonly UserBlocksInterface $blocks,
        private readonly AccountDirectoryInterface $accounts,
        private readonly ConversationPresenter $presenter,
        private readonly SeoMetadataFactoryInterface $seo,
    ) {}

    public function index(Request $request): Response
    {
        $user = $this->user($request);
        $page = max(1, (int) $request->query('pagina', '1'));
        $result = $this->list->page((string) ForumBrowser::accountId($user), $page, self::PER_PAGE);
        $lastPage = max(1, (int) ceil($result['total'] / self::PER_PAGE));
        $canStart = $this->access->mayTakePart((string) ForumBrowser::accountId($user));

        return Inertia::render('Forum/Conversations', [
            'conversations' => $this->presenter->rows($result['rows'], $user),
            'pagination' => [
                'page' => min($page, $lastPage),
                'lastPage' => $lastPage,
                'previousUrl' => $page > 1 ? route('forum.conversations.index', ['pagina' => min($page, $lastPage) - 1]) : null,
                'nextUrl' => $page < $lastPage ? route('forum.conversations.index', ['pagina' => $page + 1]) : null,
            ],
            'newUrl' => $canStart ? route('forum.conversations.create') : null,
            'startsLeft' => $canStart ? $this->service->startsLeft($user) : 0,
            'barred' => ! $canStart,
            'forumUrl' => route('forum.index'),
            'seo' => $this->seoFor(__('forum::messages.conversations.pages.index_title')),
        ]);
    }

    public function create(Request $request): Response
    {
        $user = $this->user($request);
        $canStart = $this->access->mayTakePart((string) ForumBrowser::accountId($user));

        // «Escribir a» from somewhere else: the person comes chosen when they may be written to
        $preselected = null;
        $to = AccountId::normalize((string) $request->query('para', ''));
        if ($to !== null && $to !== ForumBrowser::accountId($user) && $this->access->mayTakePart($to) && ! $this->access->isBlockedBy((string) ForumBrowser::accountId($user), $to)) {
            $name = $this->accounts->namesOf([$to])[$to] ?? null;
            $preselected = $name === null ? null : ['id' => $to, 'name' => $name];
        }

        return Inertia::render('Forum/NewConversation', [
            'storeUrl' => route('forum.conversations.store'),
            'peopleUrl' => route('forum.conversations.people'),
            'indexUrl' => route('forum.conversations.index'),
            'startsLeft' => $canStart ? $this->service->startsLeft($user) : 0,
            'barred' => ! $canStart,
            'maxPeople' => ConversationRules::MAX_PARTICIPANTS - 1,
            'titleMaxLength' => ConversationTitle::MAX_LENGTH,
            'preselected' => $preselected,
            'seo' => $this->seoFor(__('forum::messages.conversations.pages.create_title')),
        ]);
    }

    public function people(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $conversation = $request->query('conversacion');
        $conversationId = is_string($conversation) && AccountId::normalize($conversation) !== null ? ConversationId::fromString(strtolower($conversation)) : null;

        return response()->json(['people' => $this->people->search($user, (string) $request->query('q', ''), $conversationId)]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $input = Validator::make($request->json()->all(), [
            'title' => ['required', 'string', 'max:'.ConversationTitle::MAX_LENGTH],
            'recipients' => ['required', 'array', 'min:1', 'max:'.(ConversationRules::MAX_PARTICIPANTS - 1)],
            'recipients.*' => ['required', 'string', 'uuid'],
        ])->validate();

        return $this->respond(function () use ($request, $user, $input): JsonResponse {
            /** @var list<string> $recipients */
            $recipients = array_values(array_map(static fn (mixed $id): string => is_string($id) ? $id : '', (array) $input['recipients']));
            $conversation = $this->service->start($user, (string) $input['title'], $recipients, $this->documentFrom($request));

            return response()->json(['url' => route('forum.conversations.show', ['conversation' => $conversation->id()->value])], 201);
        });
    }

    public function show(Request $request, string $conversation): Response
    {
        $user = $this->user($request);
        $id = ConversationId::fromString(strtolower($conversation));

        try {
            $found = $this->service->readable($user, $id);
        } catch (ConversationNotFoundException $exception) {
            throw new NotFoundHttpException(message: $exception->getMessage(), previous: $exception);
        }

        $me = (string) ForumBrowser::accountId($user);
        $people = $this->participants->of($id);
        $lastPage = max(1, (int) ceil($found->lastNumber() / self::ENTRIES_PER_PAGE));
        $page = min($lastPage, max(1, (int) $request->query('pagina', (string) $lastPage)));
        $from = ($page - 1) * self::ENTRIES_PER_PAGE + 1;
        $to = min($found->lastNumber(), $page * self::ENTRIES_PER_PAGE);
        $entries = $this->conversations->entries($id, $from, $to);

        // Showing what is on the page reads it
        $this->service->markRead($user, $id, $to);

        $refusal = $this->access->writeRefusal($user, $found, $people);
        $barred = ! $this->access->mayTakePart($me);
        $blocked = array_column($this->blocks->blockedBy($me), 'id');

        return Inertia::render('Forum/Conversation', [
            'conversation' => [
                'id' => $found->id()->value,
                'title' => $found->title()->value,
                'url' => route('forum.conversations.show', ['conversation' => $found->id()->value]),
            ],
            'participants' => $this->presenter->participants($people, $user, $blocked),
            'entries' => $this->presenter->entries($entries, $user, $this->reports->mayReportIn($user, $id)),
            'pagination' => [
                'page' => $page,
                'lastPage' => $lastPage,
                'previousUrl' => $page > 1 ? route('forum.conversations.show', ['conversation' => $found->id()->value, 'pagina' => $page - 1]) : null,
                'nextUrl' => $page < $lastPage ? route('forum.conversations.show', ['conversation' => $found->id()->value, 'pagina' => $page + 1]) : null,
            ],
            'write' => [
                'refusal' => $refusal === null ? null : __("forum::messages.conversations.refusals.{$refusal}"),
                'storeUrl' => route('forum.conversations.messages.store', ['conversation' => $found->id()->value]),
                'readUrl' => route('forum.conversations.read', ['conversation' => $found->id()->value]),
            ],
            'addUrl' => ! $barred && in_array($refusal, [null, ConversationRefusedException::ALONE], true) && count($people) < ConversationRules::MAX_PARTICIPANTS ? route('forum.conversations.participants.store', ['conversation' => $found->id()->value]) : null,
            'peopleUrl' => route('forum.conversations.people', ['conversacion' => $found->id()->value]),
            'leaveUrl' => route('forum.conversations.leave', ['conversation' => $found->id()->value]),
            'blockUrl' => route('forum.blocks.store'),
            'unblockUrlTemplate' => route('forum.blocks.destroy', ['account' => '00000000-0000-4000-8000-000000000000']),
            'indexUrl' => route('forum.conversations.index'),
            'maxPeople' => ConversationRules::MAX_PARTICIPANTS,
            'reportReasons' => ConversationPresenter::reportReasons(),
            'seo' => $this->seoFor($found->title()->value),
        ]);
    }

    public function read(Request $request, string $conversation): JsonResponse
    {
        $user = $this->user($request);
        $number = $request->json('number');

        return $this->respond(function () use ($user, $conversation, $number): JsonResponse {
            $this->service->markRead($user, ConversationId::fromString(strtolower($conversation)), is_int($number) ? $number : 0);

            return response()->json(null, 204);
        });
    }

    public function add(Request $request, string $conversation): JsonResponse
    {
        $user = $this->user($request);
        $account = $request->json('account');

        return $this->respond(function () use ($user, $conversation, $account): JsonResponse {
            if (! is_string($account)) {
                throw ConversationRefusedException::because(ConversationRefusedException::UNAVAILABLE_PEOPLE);
            }
            $this->service->add($user, ConversationId::fromString(strtolower($conversation)), $account);

            return response()->json(['added' => true], 201);
        });
    }

    public function leave(Request $request, string $conversation): JsonResponse
    {
        $user = $this->user($request);

        return $this->respond(function () use ($user, $conversation): JsonResponse {
            $this->service->leave($user, ConversationId::fromString(strtolower($conversation)));

            return response()->json(['url' => route('forum.conversations.index')]);
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function seoFor(string $title): array
    {
        return $this->seo->forPage(new SeoPageDTO(title: $title, noindex: true))->toArray();
    }
}
