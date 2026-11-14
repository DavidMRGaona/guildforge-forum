<?php

declare(strict_types=1);

namespace Modules\Forum\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Modules\Forum\Application\Services\ForumVisibility;
use Symfony\Component\HttpFoundation\Response;

/**
 * Every /foro route: while the forum is a preview, whoever may not see it gets what a missing page
 * gets (404), never a hint that there is a forum.
 */
final readonly class EnsureForumIsVisible
{
    public function __construct(private ForumVisibility $visibility) {}

    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($this->visibility->isVisibleTo($request->user()), 404);

        return $next($request);
    }
}
