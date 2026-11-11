<?php

declare(strict_types=1);

namespace Modules\Forum\Application\Content\Contracts;

use Modules\Forum\Domain\Exceptions\InvalidDocumentException;

/**
 * One kind of mark text may carry (bold, a link), registered with the document schema.
 */
interface MarkTypeInterface
{
    /** The mark's `type`, as TipTap names it */
    public function name(): string;

    /**
     * @param  array<array-key, mixed>  $attributes  the mark's `attrs`, empty when it has none
     *
     * @throws InvalidDocumentException
     */
    public function validateAttributes(array $attributes, string $path): void;

    /**
     * Wraps already rendered (and escaped) HTML in the mark.
     *
     * @param  array<string, mixed>  $attributes  already validated
     */
    public function wrapHtml(array $attributes, string $html): string;
}
