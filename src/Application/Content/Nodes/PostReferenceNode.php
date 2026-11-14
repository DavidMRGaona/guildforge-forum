<?php

declare(strict_types=1);

namespace Modules\Forum\Application\Content\Nodes;

use Modules\Forum\Application\Content\Attributes;
use Modules\Forum\Application\Content\DocumentLimits;
use Modules\Forum\Application\Content\NodeGroup;
use Modules\Forum\Application\Content\NodeLimit;
use Modules\Forum\Application\Content\Rendering\Html;
use Modules\Forum\Application\Content\Rendering\RenderContext;
use Modules\Forum\Domain\Exceptions\InvalidDocumentException;

/**
 * A reference to another post («#12»). It keeps only the post's id, so moving and renumbering
 * posts never makes it point elsewhere: its number and place are the current ones when shown,
 * and only to a reader who may read that post. Anyone else gets the bare number, never where
 * the post is.
 *
 * The editor may send a `label` it showed while writing; it is never drawn and the post's body
 * drops it when it is saved. The node leaves nothing in the plain text: a number stored there
 * would be wrong once posts are moved.
 */
final class PostReferenceNode extends AbstractNodeType
{
    /** The longest label: «#» and a post number */
    public const int MAX_LABEL_LENGTH = 12;

    public function name(): string
    {
        return 'postReference';
    }

    public function group(): NodeGroup
    {
        return NodeGroup::Inline;
    }

    /** A post that only says «#12» says something */
    public function isSubstantial(): bool
    {
        return true;
    }

    public function limit(): NodeLimit
    {
        return new NodeLimit(DocumentLimits::MAX_REFERENCES, InvalidDocumentException::TOO_MANY_REFERENCES);
    }

    public function validateAttributes(array $attributes, string $path): void
    {
        Attributes::only($attributes, ['postId', 'label'], $path);
        Attributes::uuid($attributes, 'postId', $path);
        Attributes::optionalText($attributes, 'label', self::MAX_LABEL_LENGTH, $path);
    }

    public function toHtml(array $attributes, array $children, RenderContext $context): string
    {
        $id = Attributes::string($attributes, 'postId') ?? '';
        $view = $context->reference($id);
        $label = '#'.($view->number ?? '?');

        if ($view?->url === null) {
            return '<span class="forum-ref">'.Html::escape($label).'</span>';
        }

        return '<a class="forum-ref" href="'.Html::escape($view->url).'" data-post-id="'.Html::escape($id).'"'
            .($view->previewUrl === null ? '' : ' data-preview-url="'.Html::escape($view->previewUrl).'"')
            .($view->discussionTitle === null ? '' : ' title="'.Html::escape($view->discussionTitle).'"')
            .'>'.Html::escape($label).'</a>';
    }

    public function toText(array $attributes, array $children, RenderContext $context): string
    {
        return '';
    }
}
