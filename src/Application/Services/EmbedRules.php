<?php

declare(strict_types=1);

namespace Modules\Forum\Application\Services;

use Modules\Forum\Application\Content\Nodes\EmbedNode;
use Modules\Forum\Application\Content\PostDocument;
use Modules\Forum\Application\Content\Rendering\DocumentRenderer;
use Modules\Forum\Application\Contracts\ForumSettingsInterface;
use Modules\Forum\Domain\Exceptions\InvalidDocumentException;
use Modules\Forum\Domain\ValueObjects\PostBody;

/**
 * Whether the embeds of a document may be published: each one new to the post must be of a
 * service the forum settings have on. Embeds the post already had stay as they are, so turning
 * a service off never makes old posts uneditable.
 */
final readonly class EmbedRules
{
    public function __construct(
        private ForumSettingsInterface $settings,
        private DocumentRenderer $renderer,
    ) {}

    /**
     * @throws InvalidDocumentException
     */
    public function assertAllowed(PostDocument $document, ?PostBody $previous = null): void
    {
        $embed = (new EmbedNode)->name();
        $kept = [];
        foreach ($previous instanceof PostBody ? ($this->renderer->attributesOf($previous->document, [$embed])[$embed] ?? []) : [] as $attributes) {
            $kept[$this->key($attributes)] = true;
        }

        $enabled = $this->settings->embedServices();
        foreach ($document->attributesOf($embed) as $attributes) {
            if (! isset($kept[$this->key($attributes)]) && ! in_array($attributes['service'] ?? null, $enabled, true)) {
                throw InvalidDocumentException::because(InvalidDocumentException::EMBED_DISABLED, '', 'Posts of this service are not embedded');
            }
        }
    }

    /**
     * @param  array<array-key, mixed>  $attributes
     */
    private function key(array $attributes): string
    {
        $service = $attributes['service'] ?? null;
        $id = $attributes['id'] ?? null;

        return (is_string($service) ? $service : '').' '.(is_string($id) ? $id : '');
    }
}
