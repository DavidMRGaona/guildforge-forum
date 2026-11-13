<?php

declare(strict_types=1);

namespace Modules\Forum\Tests\Support;

use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use Modules\Forum\Application\Conversations\ConversationService;
use Modules\Forum\Domain\Entities\Conversation;

/**
 * Private conversations written the way the forum writes them.
 */
trait BuildsConversations
{
    /**
     * @return array<string, mixed>
     */
    protected function messageDocument(string $text = 'Hola, ¿quedamos el viernes?'): array
    {
        return ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $text]]]]];
    }

    protected function aConversation(UserModel $author, UserModel ...$others): Conversation
    {
        return app(ConversationService::class)->start(
            $author,
            'Partida del viernes',
            array_values(array_map(static fn (UserModel $user): string => $user->id, $others)),
            $this->messageDocument(),
        );
    }

    protected function conversations(): ConversationService
    {
        return app(ConversationService::class);
    }
}
