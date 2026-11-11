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
 * A mention of an account. It keeps only the account id: the name is the current one when
 * shown, so renaming never breaks it and no one can mention someone under another name.
 */
final class MentionNode extends AbstractNodeType
{
    public function name(): string
    {
        return 'mention';
    }

    public function group(): NodeGroup
    {
        return NodeGroup::Inline;
    }

    public function isSubstantial(): bool
    {
        return true;
    }

    public function limit(): NodeLimit
    {
        return new NodeLimit(DocumentLimits::MAX_MENTIONS, InvalidDocumentException::TOO_MANY_MENTIONS);
    }

    public function validateAttributes(array $attributes, string $path): void
    {
        Attributes::only($attributes, ['id'], $path);
        Attributes::uuid($attributes, 'id', $path);
    }

    public function toHtml(array $attributes, array $children, RenderContext $context): string
    {
        $id = Attributes::string($attributes, 'id') ?? '';

        return '<span class="forum-mention" data-account-id="'.Html::escape($id).'">'.Html::escape($this->label($id, $context)).'</span>';
    }

    public function toText(array $attributes, array $children, RenderContext $context): string
    {
        return $this->label(Attributes::string($attributes, 'id') ?? '', $context);
    }

    private function label(string $id, RenderContext $context): string
    {
        return '@'.($context->accountName($id) ?? __('forum::messages.content.unknown_account'));
    }
}
