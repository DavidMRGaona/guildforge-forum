<?php

declare(strict_types=1);

namespace Modules\Forum\Application\Services;

use Illuminate\Contracts\Auth\Authenticatable;
use Modules\Forum\Application\Content\DocumentSchema;
use Modules\Forum\Application\Contracts\AccountDirectoryInterface;
use Modules\Forum\Application\Contracts\ForumAccessInterface;
use Modules\Forum\Domain\Entities\Discussion;
use Modules\Forum\Domain\Exceptions\InvalidDocumentException;
use Modules\Forum\Domain\Repositories\DiscussionRepositoryInterface;
use Modules\Forum\Domain\Repositories\PostRepositoryInterface;
use Modules\Forum\Domain\ValueObjects\PostBody;
use Modules\Forum\Domain\ValueObjects\PostId;

/**
 * Turns what the editor sent into a post body: the document checked against the schema, its
 * mentions pointing at accounts that may be mentioned, its quotes at posts the author may read,
 * and its plain text derived from it. Anything wrong is refused as it came.
 */
final readonly class PostBodyFactory
{
    public function __construct(
        private DocumentSchema $schema,
        private PostRenderer $renderer,
        private AccountDirectoryInterface $accounts,
        private PostRepositoryInterface $posts,
        private DiscussionRepositoryInterface $discussions,
        private ForumAccessInterface $access,
    ) {}

    /**
     * @throws InvalidDocumentException
     */
    public function fromInput(mixed $raw, Authenticatable $author): PostBody
    {
        $document = $this->schema->validate($raw);

        $mentioned = $document->mentionedAccountIds();
        if (array_diff($mentioned, $this->accounts->mentionable($mentioned)) !== []) {
            throw InvalidDocumentException::because(InvalidDocumentException::UNKNOWN_ACCOUNT, '', 'A mentioned account cannot be mentioned');
        }

        $this->assertQuotesReadable($document->quotedPostIds(), $author);

        $text = $this->renderer->toText($document);
        if (mb_strlen($text) > PostBody::MAX_PLAIN_TEXT_LENGTH) {
            throw InvalidDocumentException::because(InvalidDocumentException::TOO_LONG, '', 'The post is too long');
        }

        return new PostBody($document->toArray(), $text);
    }

    /**
     * Every quoted post exists, is visible and is in a discussion the author may read.
     *
     * @param  list<string>  $postIds
     *
     * @throws InvalidDocumentException
     */
    private function assertQuotesReadable(array $postIds, Authenticatable $author): void
    {
        $readable = 0;
        foreach ($this->posts->findMany(array_map(PostId::fromString(...), $postIds)) as $post) {
            $discussion = $this->discussions->find($post->discussionId());
            if ($post->isVisible() && $discussion instanceof Discussion && $this->access->canRead($author, $discussion)) {
                $readable++;
            }
        }

        if ($readable !== count($postIds)) {
            throw InvalidDocumentException::because(InvalidDocumentException::UNKNOWN_POST, '', 'A quoted post cannot be read');
        }
    }
}
