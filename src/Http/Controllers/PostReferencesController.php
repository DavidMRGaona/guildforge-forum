<?php

declare(strict_types=1);

namespace Modules\Forum\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/**
 * A post as a reference to it shows it, and the posts that reply to it. Whoever may not read a
 * post gets what a missing one gets.
 */
final class PostReferencesController extends Controller
{
    public function preview(string $post): JsonResponse
    {
        abort(404);
    }
}
