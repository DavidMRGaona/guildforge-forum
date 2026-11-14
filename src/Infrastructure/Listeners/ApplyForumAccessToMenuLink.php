<?php

declare(strict_types=1);

namespace Modules\Forum\Infrastructure\Listeners;

use App\Domain\Modules\Events\ModuleEnabled;
use App\Domain\Modules\Events\ModuleUpdated;
use Modules\Forum\Application\Services\ForumMenuLink;
use Throwable;

/**
 * Enabling or updating the forum puts its menu link in line with «Acceso al foro», so a site that
 * already had the forum open to everyone starts the preview with this release too. A failure is
 * reported and never stops the module change: the routes answer 404 anyway.
 */
final readonly class ApplyForumAccessToMenuLink
{
    public function __construct(private ForumMenuLink $link) {}

    public function handle(ModuleEnabled|ModuleUpdated $event): void
    {
        if ($event->moduleName !== 'forum') {
            return;
        }

        try {
            $this->link->applyAccess();
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
