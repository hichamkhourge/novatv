<?php

use App\Http\Controllers\IptvController;
use App\Http\Middleware\IptvAuthMiddleware;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| IPTV Client Routes
|--------------------------------------------------------------------------
|
| These routes are for IPTV clients (TiviMate, IPTV Smarters, VLC, etc.)
| Authentication is performed inside the middleware via username/password
| query params — NOT via Laravel's standard auth system.
|
*/

// M3U Playlist download
Route::get('/get.php', [IptvController::class, 'getPlaylist'])
    ->middleware(IptvAuthMiddleware::class)
    ->name('iptv.playlist');

// Xtream Codes API — handles multiple actions via ?action= param
Route::match(['get', 'post'], '/player_api.php', [IptvController::class, 'playerApi'])
    ->middleware(IptvAuthMiddleware::class)
    ->name('iptv.player_api');

// Stream proxy — authentication is done inline inside the controller
// to avoid middleware adding overhead to hot-path streaming
Route::get('/live/{username}/{password}/{stream_id}', [IptvController::class, 'streamProxy'])
    ->where('stream_id', '.*\.(ts|m3u8)')
    ->name('iptv.stream');
