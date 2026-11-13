<?php

declare(strict_types=1);

namespace Modules\Forum\Http\Presenters;

use Illuminate\Contracts\Auth\Authenticatable;
use Modules\Forum\Application\Conversations\ConversationAccess;

/**
 * «Escribir un mensaje privado» next to the author of each post (forum#88): the page that starts
 * a conversation with that person, only when the reader could start it. Posts that show no person
 * (the forum's own account, a character's hidden author) and posts not shown get none.
 */
final readonly class PrivateMessageLinks
{
    public function __construct(
        private ConversationAccess $access,
    ) {}

    /**
     * Adds `messageUrl` to each presented post.
     *
     * @param  list<array<string, mixed>>  $posts
     * @return list<array<string, mixed>>
     */
    public function withMessageLinks(array $posts, ?Authenticatable $reader): array
    {
        $authors = [];
        foreach ($posts as $post) {
            if (($post['status'] ?? null) === 'visible' && is_string($post['authorId'] ?? null)) {
                $authors[] = $post['authorId'];
            }
        }
        $writable = array_flip($this->access->writableAmong($reader, array_values(array_unique($authors))));

        return array_map(static function (array $post) use ($writable): array {
            $authorId = is_string($post['authorId'] ?? null) ? strtolower($post['authorId']) : null;

            return [
                ...$post,
                'messageUrl' => ($post['status'] ?? null) === 'visible' && $authorId !== null && isset($writable[$authorId])
                    ? route('forum.conversations.create', ['para' => $authorId])
                    : null,
            ];
        }, $posts);
    }
}
