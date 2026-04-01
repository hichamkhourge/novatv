<?php

use App\Http\Controllers\XtreamController;
use App\Http\Middleware\XtreamAuth;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Xtream Codes API Routes
|--------------------------------------------------------------------------
|
| Routes for Xtream Codes compatible API
| Used by IPTV apps like TiviMate, IPTV Smarters, VLC, etc.
|
*/

// Player API - handles multiple actions
Route::match(['get', 'post'], '/player_api.php', [XtreamController::class, 'playerApi'])
    ->middleware(XtreamAuth::class)
    ->name('xtream.player_api');

// M3U Playlist download
Route::get('/get.php', [XtreamController::class, 'getPlaylist'])
    ->middleware(XtreamAuth::class)
    ->name('xtream.playlist');

// Stream proxy - redirects to real stream URL
Route::get('/live/{username}/{password}/{stream_id}', [XtreamController::class, 'streamProxy'])
    ->where('stream_id', '.*\.(ts|m3u8)') // Match .ts or .m3u8 extensions
    ->name('xtream.stream');
