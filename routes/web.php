<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Forum\ForumServiceProvider;
use Modules\Forum\Http\Controllers\CategoryController;
use Modules\Forum\Http\Controllers\DiscussionController;
use Modules\Forum\Http\Controllers\FollowsController;
use Modules\Forum\Http\Controllers\ForumIndexController;
use Modules\Forum\Http\Controllers\ForumPreferencesController;
use Modules\Forum\Http\Controllers\MentionCandidatesController;
use Modules\Forum\Http\Controllers\ModerationController;
use Modules\Forum\Http\Controllers\ModerationQueueController;
use Modules\Forum\Http\Controllers\PostController;
use Modules\Forum\Http\Controllers\PostImageController;
use Modules\Forum\Http\Controllers\PostLikesController;
use Modules\Forum\Http\Controllers\PostVersionsController;
use Modules\Forum\Http\Controllers\ReadMarksController;
use Modules\Forum\Http\Controllers\SubjectConversationController;
use Modules\Forum\Http\Controllers\ReportController;
use Modules\Forum\Http\Controllers\SuspensionController;

/*
|--------------------------------------------------------------------------
| Web routes of the forum module
|--------------------------------------------------------------------------
|
| The module loader already applies the "web" middleware. Public URL
| segments are shown to visitors: translate them to Spanish (e.g. "mesas").
|
*/

Route::prefix('foro')
    ->name('forum.')
    ->group(function (): void {
        // Public pages: what a reader may not read answers 404, never 403
        Route::get('/', ForumIndexController::class)->name('index');
        Route::get('c/{category}', [CategoryController::class, 'show'])
            ->where('category', '[a-z0-9]+(?:-[a-z0-9]+)*')
            ->name('categories.show');
        Route::get('t/{discussion}/{post?}', [DiscussionController::class, 'show'])
            ->where(['discussion' => '[1-9][0-9]{0,9}(?:-[a-z0-9-]*)?', 'post' => '[1-9][0-9]{0,9}'])
            ->name('discussions.show');

        // The conversation a record's page shows (JSON). Any type and id reach the controller,
        // which answers an empty conversation for what it cannot show, never an error
        Route::get('fichas/{type}/{id}', [SubjectConversationController::class, 'show'])
            ->where(['type' => '[^/]{1,64}', 'id' => '[^/]{1,100}'])
            ->middleware('throttle:120,1,forum-subjects')
            ->name('subjects.show');

        Route::middleware('auth')->group(function (): void {
            Route::get('nuevo', [DiscussionController::class, 'create'])->name('discussions.create');
            // What awaits whoever moderates something: reports and posts awaiting approval
            Route::get('moderacion', ModerationQueueController::class)->name('moderation.index');
            // Starting a discussion counts against the posts-per-minute limit too
            Route::post('temas', [DiscussionController::class, 'store'])
                ->middleware('throttle:20,1,forum-discussions')
                ->name('discussions.store');
            Route::post('temas/{discussion}/leido', [ReadMarksController::class, 'discussion'])
                ->whereUuid('discussion')
                ->middleware('throttle:120,1,forum-reads')
                ->name('discussions.read');
            Route::post('leido', [ReadMarksController::class, 'forum'])
                ->middleware('throttle:20,1,forum-reads-all')
                ->name('read');
            Route::post('c/{category}/leido', [ReadMarksController::class, 'category'])
                ->where('category', '[a-z0-9]+(?:-[a-z0-9]+)*')
                ->middleware('throttle:20,1,forum-reads-all')
                ->name('categories.read');
        });

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
            // Moderation menus of a discussion and of each post; every action is logged
            Route::middleware('throttle:60,1,forum-moderation')->group(function (): void {
                Route::post('temas/{discussion}/moderacion', [ModerationController::class, 'discussion'])
                    ->whereUuid('discussion')
                    ->name('moderation.discussions');
                Route::delete('temas/{discussion}', [ModerationController::class, 'destroyDiscussion'])
                    ->whereUuid('discussion')
                    ->name('moderation.discussions.destroy');
                Route::post('mensajes/{post}/moderacion', [ModerationController::class, 'post'])
                    ->whereUuid('post')
                    ->name('moderation.posts');
                Route::post('mensajes/{post}/denuncias/resolver', [ReportController::class, 'resolve'])
                    ->whereUuid('post')
                    ->name('reports.resolve');
                Route::post('suspensiones', [SuspensionController::class, 'store'])->name('suspensions.store');
                Route::delete('suspensiones/{suspension}', [SuspensionController::class, 'destroy'])
                    ->whereUuid('suspension')
                    ->name('suspensions.destroy');
            });
            Route::post('mensajes/{post}/denuncias', [ReportController::class, 'store'])
                ->whereUuid('post')
                ->middleware('throttle:10,1,forum-reports')
                ->name('reports.store');
            Route::post('imagenes', [PostImageController::class, 'store'])
                ->middleware('throttle:'.ForumServiceProvider::IMAGE_UPLOADS_LIMITER)
                ->name('images.store');
        });

        // Likes, follows and the forum's preferences, all JSON
        Route::middleware('auth')->group(function (): void {
            Route::post('mensajes/{post}/me-gusta', [PostLikesController::class, 'store'])
                ->whereUuid('post')
                ->middleware('throttle:60,1,forum-likes')
                ->name('posts.like');
            Route::delete('mensajes/{post}/me-gusta', [PostLikesController::class, 'destroy'])
                ->whereUuid('post')
                ->middleware('throttle:60,1,forum-likes')
                ->name('posts.unlike');
            Route::put('temas/{discussion}/seguimiento', [FollowsController::class, 'discussion'])
                ->whereUuid('discussion')
                ->middleware('throttle:30,1,forum-follows')
                ->name('discussions.follow');
            Route::put('c/{category}/seguimiento', [FollowsController::class, 'category'])
                ->where('category', '[a-z0-9]+(?:-[a-z0-9]+)*')
                ->middleware('throttle:30,1,forum-follows')
                ->name('categories.follow');
            Route::get('preferencias', [ForumPreferencesController::class, 'show'])
                ->middleware('throttle:60,1,forum-preferences')
                ->name('preferences.show');
            Route::put('preferencias', [ForumPreferencesController::class, 'update'])
                ->middleware('throttle:30,1,forum-preferences')
                ->name('preferences.update');
        });
    });
