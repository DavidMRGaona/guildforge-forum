<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

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
    ->group(function (): void {});
