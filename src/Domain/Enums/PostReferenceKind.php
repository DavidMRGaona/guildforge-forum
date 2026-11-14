<?php

declare(strict_types=1);

namespace Modules\Forum\Domain\Enums;

/**
 * How a post points at another: «#N» or a quotation of it.
 */
enum PostReferenceKind: string
{
    case Reference = 'reference';
    case Quote = 'quote';
}
