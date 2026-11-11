<?php

declare(strict_types=1);

namespace Modules\Forum\Application\Content;

use Modules\Forum\Domain\ValueObjects\AccountId;
use Modules\Forum\Domain\ValueObjects\PostBody;

/**
 * Whom a stored post mentions and which posts it quotes, read from its document. The document
 * passed the schema when it was written; here only what still reads as a UUID counts.
 */
final readonly class DocumentReferences
{
    /**
     * @param  list<string>  $mentionedAccountIds
     * @param  list<string>  $quotedPostIds
     */
    private function __construct(
        public array $mentionedAccountIds,
        public array $quotedPostIds,
    ) {}

    public static function of(PostBody $body): self
    {
        $mentions = [];
        $quotes = [];
        self::walk($body->document, $mentions, $quotes);

        return new self(array_keys($mentions), array_keys($quotes));
    }

    /**
     * @param  array<mixed>  $node
     * @param  array<string, true>  $mentions
     * @param  array<string, true>  $quotes
     */
    private static function walk(array $node, array &$mentions, array &$quotes): void
    {
        $attributes = is_array($node['attrs'] ?? null) ? $node['attrs'] : [];
        $type = $node['type'] ?? null;

        if ($type === 'mention' && is_string($attributes['id'] ?? null) && ($id = AccountId::normalize($attributes['id'])) !== null) {
            $mentions[$id] = true;
        }

        if ($type === 'postQuote' && is_string($attributes['postId'] ?? null) && ($id = AccountId::normalize($attributes['postId'])) !== null) {
            $quotes[$id] = true;
        }

        foreach (is_array($node['content'] ?? null) ? $node['content'] : [] as $child) {
            if (is_array($child)) {
                self::walk($child, $mentions, $quotes);
            }
        }
    }
}
