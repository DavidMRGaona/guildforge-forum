<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Forum\ForumServiceProvider;
use Modules\Forum\Http\Controllers\BlocksController;
use Modules\Forum\Http\Controllers\CategoryController;
use Modules\Forum\Http\Controllers\CharacterController;
use Modules\Forum\Http\Controllers\ConversationController;
use Modules\Forum\Http\Controllers\ConversationMessageController;
use Modules\Forum\Http\Controllers\ConversationReportController;
use Modules\Forum\Http\Controllers\DiscussionController;
use Modules\Forum\Http\Controllers\EmbedController;
use Modules\Forum\Http\Controllers\ExternalImageController;
use Modules\Forum\Http\Controllers\FollowsController;
use Modules\Forum\Http\Controllers\ForumIndexController;
use Modules\Forum\Http\Controllers\ForumPreferencesController;
use Modules\Forum\Http\Controllers\ForumSearchController;
use Modules\Forum\Http\Controllers\LiveUpdatesController;
use Modules\Forum\Http\Controllers\MentionCandidatesController;
use Modules\Forum\Http\Controllers\ModerationController;
use Modules\Forum\Http\Controllers\ModerationQueueController;
use Modules\Forum\Http\Controllers\PostController;
use Modules\Forum\Http\Controllers\PostImageController;
use Modules\Forum\Http\Controllers\PostLikesController;
use Modules\Forum\Http\Controllers\PostVersionsController;
use Modules\Forum\Http\Controllers\ReadMarksController;
use Modules\Forum\Http\Controllers\ReportController;
use Modules\Forum\Http\Controllers\RollController;
use Modules\Forum\Http\Controllers\SharedCharacterController;
use Modules\Forum\Http\Controllers\SubjectConversationController;
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
        // The forum's own search; the engine is the site's, so a query costs about as much as in /buscar
        Route::get('buscar', ForumSearchController::class)
            ->middleware('throttle:60,1,forum-search')
            ->name('search');
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

        // What an open page asks for while it is open. Whoever may not read a discussion gets what
        // a missing one gets; the limits leave room for a few tabs asking every 20 s with backoff
        Route::get('temas/{discussion}/novedades', [LiveUpdatesController::class, 'posts'])
            ->whereUuid('discussion')
            ->middleware('throttle:30,1,forum-updates')
            ->name('discussions.updates');
        Route::get('novedades', [LiveUpdatesController::class, 'forum'])
            ->middleware(['auth', 'throttle:20,1,forum-pulse'])
            ->name('updates');

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
            // Revealing a hidden roll, for good
            Route::post('tiradas/{roll}/revelar', [RollController::class, 'reveal'])
                ->whereUuid('roll')
                ->middleware('throttle:30,1,forum-edits')
                ->name('rolls.reveal');
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
                Route::post('temas/{discussion}/separar', [ModerationController::class, 'split'])
                    ->whereUuid('discussion')
                    ->name('moderation.discussions.split');
                Route::post('temas/{discussion}/unir', [ModerationController::class, 'merge'])
                    ->whereUuid('discussion')
                    ->name('moderation.discussions.merge');
                Route::get('temas/{discussion}/unir/destino', [ModerationController::class, 'mergeTarget'])
                    ->whereUuid('discussion')
                    ->name('moderation.discussions.merge-target');
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
            // The forum's copy of an image from another site: fetched by the server, like an upload
            Route::post('imagenes/externas', [ExternalImageController::class, 'store'])
                ->middleware('throttle:'.ForumServiceProvider::IMAGE_UPLOADS_LIMITER)
                ->name('images.external');
            // What a link to a post of another service is, with its card fetched by the server
            Route::post('incrustaciones', [EmbedController::class, 'store'])
                ->middleware('throttle:30,10,forum-embeds')
                ->name('embeds.store');
        });

        // Private conversations (forum#18). Only the people in one read it: anyone else, moderators
        // and administrators included, gets what a missing conversation gets (404)
        Route::middleware('auth')->prefix('conversaciones')->name('conversations.')->group(function (): void {
            Route::get('/', [ConversationController::class, 'index'])->name('index');
            Route::get('nueva', [ConversationController::class, 'create'])->name('create');
            Route::get('personas', [ConversationController::class, 'people'])
                ->middleware('throttle:60,1,forum-conversation-people')
                ->name('people');
            Route::post('/', [ConversationController::class, 'store'])
                ->middleware('throttle:20,1,forum-conversation-starts')
                ->name('store');
            Route::get('{conversation}', [ConversationController::class, 'show'])
                ->whereUuid('conversation')
                ->name('show');
            Route::post('{conversation}/mensajes', [ConversationMessageController::class, 'store'])
                ->whereUuid('conversation')
                ->name('messages.store');
            // What an open conversation asks for while it is open (forum#89): after its last entry,
            // and the entries it was told changed; real time only says when
            Route::get('{conversation}/novedades', [ConversationController::class, 'updates'])
                ->whereUuid('conversation')
                ->middleware('throttle:60,1,forum-conversation-updates')
                ->name('updates');
            Route::post('{conversation}/leido', [ConversationController::class, 'read'])
                ->whereUuid('conversation')
                ->middleware('throttle:120,1,forum-conversation-reads')
                ->name('read');
            Route::middleware('throttle:30,1,forum-conversation-people-changes')->group(function (): void {
                Route::post('{conversation}/personas', [ConversationController::class, 'add'])
                    ->whereUuid('conversation')
                    ->name('participants.store');
                Route::post('{conversation}/salir', [ConversationController::class, 'leave'])
                    ->whereUuid('conversation')
                    ->name('leave');
            });
            Route::delete('mensajes/{message}', [ConversationMessageController::class, 'destroy'])
                ->whereUuid('message')
                ->middleware('throttle:30,1,forum-conversation-edits')
                ->name('messages.destroy');
            Route::post('mensajes/{message}/denuncias', [ConversationReportController::class, 'store'])
                ->whereUuid('message')
                ->middleware('throttle:10,1,forum-reports')
                ->name('reports.store');
            // Whoever moderates the whole forum settles what was reported, from its copy
            Route::post('denuncias/{report}/resolver', [ConversationReportController::class, 'resolve'])
                ->whereUuid('report')
                ->middleware('throttle:60,1,forum-moderation')
                ->name('reports.resolve');
        });

        // Who the person does not want private messages from: listed in the profile
        Route::middleware('auth')->group(function (): void {
            Route::get('bloqueos', [BlocksController::class, 'index'])
                ->middleware('throttle:60,1,forum-blocks')
                ->name('blocks.index');
            Route::middleware('throttle:30,1,forum-blocks-write')->group(function (): void {
                Route::post('bloqueos', [BlocksController::class, 'store'])->name('blocks.store');
                Route::delete('bloqueos/{account}', [BlocksController::class, 'destroy'])
                    ->whereUuid('account')
                    ->name('blocks.destroy');
            });
        });

        // Likes, follows, characters and the forum's preferences, all JSON
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
            // Characters: the profile tab lists one's own; their owner and moderators change them
            Route::get('personajes', [CharacterController::class, 'index'])
                ->middleware('throttle:60,1,forum-characters')
                ->name('characters.index');
            // The shared characters (PNJ) of a campaign's space, for whoever directs the campaign
            Route::get('c/{category}/pnj', [SharedCharacterController::class, 'index'])
                ->where('category', '[a-z0-9]+(?:-[a-z0-9]+)*')
                ->middleware('throttle:60,1,forum-characters')
                ->name('characters.shared.index');
            Route::middleware('throttle:30,1,forum-characters-write')->group(function (): void {
                Route::post('c/{category}/pnj', [SharedCharacterController::class, 'store'])
                    ->where('category', '[a-z0-9]+(?:-[a-z0-9]+)*')
                    ->name('characters.shared.store');
                Route::post('personajes', [CharacterController::class, 'store'])->name('characters.store');
                // Multipart (a portrait may come with it), hence POST
                Route::post('personajes/{character}', [CharacterController::class, 'update'])
                    ->whereUuid('character')
                    ->name('characters.update');
                Route::post('personajes/{character}/retirar', [CharacterController::class, 'retire'])
                    ->whereUuid('character')
                    ->name('characters.retire');
                Route::post('personajes/{character}/recuperar', [CharacterController::class, 'restore'])
                    ->whereUuid('character')
                    ->name('characters.restore');
                Route::delete('personajes/{character}', [CharacterController::class, 'destroy'])
                    ->whereUuid('character')
                    ->name('characters.destroy');
            });
            Route::get('preferencias', [ForumPreferencesController::class, 'show'])
                ->middleware('throttle:60,1,forum-preferences')
                ->name('preferences.show');
            Route::put('preferencias', [ForumPreferencesController::class, 'update'])
                ->middleware('throttle:30,1,forum-preferences')
                ->name('preferences.update');
        });
    });
