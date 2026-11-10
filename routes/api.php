<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API routes of the forum module
|--------------------------------------------------------------------------
|
| The module loader already applies the "api" middleware and the "/api"
| prefix: these routes answer under /api/forum/...
|
*/

Route::prefix('forum')
    ->name('forum.api.')
    ->group(function (): void {});
