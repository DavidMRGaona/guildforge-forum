<?php

declare(strict_types=1);

namespace Modules\Forum\Application\Contracts;

use Modules\Forum\Domain\Enums\ForumAccessMode;
use RuntimeException;

/**
 * «Acceso al foro» as it is stored now, read on every call. ForumSettingsInterface::access() reads
 * the configuration the process booted with, which a queue worker keeps for as long as it lives;
 * what is decided in the queue right after the setting changes (the activity contract, forum#151)
 * asks here instead.
 */
interface StoredForumAccessInterface
{
    /**
     * The stored value, or the release default when none was saved; an unknown value is a preview.
     *
     * @throws RuntimeException when the stored settings cannot be read
     */
    public function current(): ForumAccessMode;
}
