<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Forum\Http\Controllers\MentionCandidatesController;
use Modules\Forum\Http\Controllers\PostController;
use Modules\Forum\Http\Controllers\PostImageController;
use Modules\Forum\Http\Controllers\PostVersionsController;

/*
|--------------------------------------------------------------------------
| Web routes of the forum module
|--------------------------------------------------------------------------
|
| The module loader already applies the "web" middleware. Public URL
| segments are shown to visitors: translate them to Spanish (e.g. "mesas").
|
*/

// The public pages (/foro) arrive with the discussion lists
Route::prefix('foro')
    ->name('forum.')
    ->group(function (): void {
        // The editor's endpoints, all JSON. `auth` also brings the site's email verification
        // rule (EnsureEmailIsVerifiedWhenRequired), so unverified accounts get a 403 here
        Route::middleware('auth')->group(function (): void {
            Route::post('temas/{discussion}/mensajes', [PostController::class, 'store'])
                ->whereUuid('discussion')
                ->name('posts.store');
            // Every edit keeps a version: edits and deletions have a limit of their own
            Route::patch('mensajes/{post}', [PostController::class, 'update'])
                ->whereUuid('post')
                ->middleware('throttle:30,1,forum-edits')
                ->name('posts.update');
            Route::delete('mensajes/{post}', [PostController::class, 'destroy'])
                ->whereUuid('post')
                ->middleware('throttle:30,1,forum-edits')
                ->name('posts.destroy');
            Route::get('mensajes/{post}/versiones', [PostVersionsController::class, 'index'])
                ->whereUuid('post')
                ->name('posts.versions');
            Route::get('temas/{discussion}/menciones', [MentionCandidatesController::class, 'index'])
                ->whereUuid('discussion')
                ->middleware('throttle:120,1,forum-mentions')
                ->name('mentions.index');
            Route::post('imagenes', [PostImageController::class, 'store'])
                ->middleware('throttle:30,60,forum-images')
                ->name('images.store');
        });
    });
