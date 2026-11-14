<?php

declare(strict_types=1);

namespace Modules\Forum\Application\Content;

use Modules\Forum\Domain\ValueObjects\AccountId;
use Modules\Forum\Domain\ValueObjects\PostBody;

/**
 * Whom a stored post mentions and which posts it quotes and references, read from its document. The document
 * passed the schema when it was written; here only what still reads as a UUID counts.
 */
final readonly class DocumentReferences
{
    /**
     * @param  list<string>  $mentionedAccountIds
     * @param  list<string>  $quotedPostIds
     * @param  list<string>  $referencedPostIds  the posts it points at with «#N»
     */
    private function __construct(
        public array $mentionedAccountIds,
        public array $quotedPostIds,
        public array $referencedPostIds = [],
    ) {}

    public static function of(PostBody $body): self
    {
        $mentions = [];
        $quotes = [];
        $references = [];
        self::walk($body->document, $mentions, $quotes, $references);

        return new self(array_keys($mentions), array_keys($quotes), array_keys($references));
    }

    /**
     * @param  array<mixed>  $node
     * @param  array<string, true>  $mentions
     * @param  array<string, true>  $quotes
     * @param  array<string, true>  $references
     */
    private static function walk(array $node, array &$mentions, array &$quotes, array &$references): void
    {
        $attributes = is_array($node['attrs'] ?? null) ? $node['attrs'] : [];
        $type = $node['type'] ?? null;

        if ($type === 'mention' && is_string($attributes['id'] ?? null) && ($id = AccountId::normalize($attributes['id'])) !== null) {
            $mentions[$id] = true;
        }

        if ($type === 'postQuote' && is_string($attributes['postId'] ?? null) && ($id = AccountId::normalize($attributes['postId'])) !== null) {
            $quotes[$id] = true;
        }

        if ($type === 'postReference' && is_string($attributes['postId'] ?? null) && ($id = AccountId::normalize($attributes['postId'])) !== null) {
            $references[$id] = true;
        }

        foreach (is_array($node['content'] ?? null) ? $node['content'] : [] as $child) {
            if (is_array($child)) {
                self::walk($child, $mentions, $quotes, $references);
            }
        }
    }
}
