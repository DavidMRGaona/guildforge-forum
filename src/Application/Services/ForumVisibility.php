<?php

declare(strict_types=1);

namespace Modules\Forum\Application\Services;

use App\Application\Authorization\Services\AuthorizationServiceInterface;
use Illuminate\Contracts\Auth\Authenticatable;
use Modules\Forum\Application\Contracts\ForumSettingsInterface;
use Modules\Forum\Domain\Enums\ForumAccessMode;
use Throwable;

/**
 * The one rule for whether someone sees the forum at all (the setting «Acceso al foro»). Open, it
 * is the categories' own rules that decide; as a preview, only whoever holds forum:forum.preview
 * sees it, and for anyone else, a forum moderator included, it does not exist: pages, menu link,
 * slots, search, real time, guides and notices all ask here. It fails closed: an unreadable
 * setting is a preview, and a permission check that throws (reported) is a no.
 */
final readonly class ForumVisibility
{
    public const string PERMISSION = 'forum:forum.preview';

    public function __construct(
        private ForumSettingsInterface $settings,
        private AuthorizationServiceInterface $authorization,
    ) {}

    public function isOpen(): bool
    {
        return $this->settings->access() === ForumAccessMode::Open;
    }

    public function isVisibleTo(?object $user): bool
    {
        if ($this->isOpen()) {
            return true;
        }

        if (! $user instanceof Authenticatable) {
            return false;
        }

        try {
            return $this->authorization->can($user, self::PERMISSION);
        } catch (Throwable $exception) {
            report($exception);

            return false;
        }
    }
}
