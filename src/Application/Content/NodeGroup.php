<?php

declare(strict_types=1);

namespace Modules\Forum\Application\Content;

/**
 * Where a node may go: among blocks (paragraphs, lists, images) or inside a block's text.
 */
enum NodeGroup: string
{
    case Block = 'block';
    case Inline = 'inline';
}
