<?php

declare(strict_types=1);

namespace Modules\Forum\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Every endpoint that puts the person's name in front of others (a new discussion, a reply, an
 * edit, a private message, a like) needs a public name (`display_name`): without one the forum
 * would show their account name. Decided here, on the server, with a translated 422 the editor
 * shows; the pages only help the person set it first. What is already published is untouched,
 * and signing in or reading never asks for it.
 */
final readonly class RequirePublicName
{
    public const string REASON = 'public_name_required';

    public function handle(Request $request, Closure $next): Response
    {
        $name = $request->user()?->display_name;

        if (is_string($name) && trim($name) !== '') {
            return $next($request);
        }

        // Guests are the `auth` middleware's business, which runs first
        $message = __('forum::messages.errors.public_name_required');

        return response()->json(['message' => $message, 'reason' => self::REASON, 'errors' => ['display_name' => [$message]]], 422);
    }
}
