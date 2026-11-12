<?php

declare(strict_types=1);

namespace Modules\Forum\Application\Content\Contracts;

/**
 * A node whose content is said out of character: what is inside it never speaks as the post's
 * character (a roll inside says nobody's name). The renderer reads it; it has no methods.
 */
interface OutOfCharacterInterface {}
