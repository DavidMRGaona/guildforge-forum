<?php

declare(strict_types=1);

namespace Modules\Forum\Application\Services;

use Illuminate\Contracts\Auth\Authenticatable;
use Modules\Forum\Application\Content\DocumentSchema;
use Modules\Forum\Application\Content\PostDocument;
use Modules\Forum\Application\Contracts\AccountDirectoryInterface;
use Modules\Forum\Application\Contracts\ForumAccessInterface;
use Modules\Forum\Domain\Entities\Discussion;
use Modules\Forum\Domain\Entities\Post;
use Modules\Forum\Domain\Exceptions\InvalidDocumentException;
use Modules\Forum\Domain\Repositories\DiscussionRepositoryInterface;
use Modules\Forum\Domain\Repositories\PostRepositoryInterface;
use Modules\Forum\Domain\ValueObjects\PostBody;
use Modules\Forum\Domain\ValueObjects\PostId;

/**
 * Turns what the editor sent into a post body: the document checked against the schema, its
 * images from other sites allowed, its mentions pointing at accounts that may be mentioned, its
 * quotes at posts the author may read, and its plain text derived from it. Anything wrong is refused as it came.
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
        private ExternalImageRules $externalImages,
    ) {}

    /**
     * @param  PostBody|null  $previous  what the post said before, when it is an edit: images from other sites it already had are kept without checking them again
     *
     * @throws InvalidDocumentException
     */
    public function fromInput(mixed $raw, Authenticatable $author, ?PostBody $previous = null): PostBody
    {
        $document = $this->schema->validate($raw);

        $this->externalImages->assertAllowed($document, $author, $previous);

        $mentioned = $document->mentionedAccountIds();
        if (array_diff($mentioned, $this->accounts->mentionable($mentioned)) !== []) {
            throw InvalidDocumentException::because(InvalidDocumentException::UNKNOWN_ACCOUNT, '', 'A mentioned account cannot be mentioned');
        }

        $this->assertQuotesHold($document, $author);

        $text = $this->renderer->toText($document);
        if (mb_strlen($text) > PostBody::MAX_PLAIN_TEXT_LENGTH) {
            throw InvalidDocumentException::because(InvalidDocumentException::TOO_LONG, '', 'The post is too long');
        }

        return new PostBody($document->toArray(), $text);
    }

    /**
     * Every quoted post exists, is visible and is in a discussion the author may read, and
     * every quotation holds words of that post: a quote shows who wrote the original, so it
     * cannot put words in someone else's mouth. Only letters and digits are compared, so
     * formatting and spacing do not matter.
     *
     * @throws InvalidDocumentException
     */
    private function assertQuotesHold(PostDocument $document, Authenticatable $author): void
    {
        $postIds = $document->quotedPostIds();

        /** @var array<string, Post> $readable */
        $readable = [];
        foreach ($this->posts->findMany(array_map(PostId::fromString(...), $postIds)) as $post) {
            $discussion = $this->discussions->find($post->discussionId());
            if ($post->isVisible() && $discussion instanceof Discussion && $this->access->canRead($author, $discussion)) {
                $readable[$post->id()->value] = $post;
            }
        }

        if (count($readable) !== count($postIds)) {
            throw InvalidDocumentException::because(InvalidDocumentException::UNKNOWN_POST, '', 'A quoted post cannot be read');
        }

        foreach ($this->renderer->quotations($document) as $quotation) {
            $original = $readable[$quotation['postId']] ?? null;
            if (! $original instanceof Post || ! str_contains($this->words($original->body()->plainText), $this->words($quotation['text']))) {
                throw InvalidDocumentException::because(InvalidDocumentException::INVALID_QUOTE, '', 'A quotation does not match the quoted post');
            }
        }
    }

    private function words(string $text): string
    {
        return mb_strtolower(preg_replace('/[^\p{L}\p{N}]+/u', '', $text) ?? '');
    }
}
