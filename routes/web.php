<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\PlaylistController;
use App\Http\Controllers\StreamController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// Internal nginx auth_request endpoint (called by nginx, not directly by clients)
Route::get('/api/auth/stream', [AuthController::class, 'authenticateStream'])->name('auth.stream');

// IPTV API Routes with rate limiting
Route::middleware('throttle:60,1')->group(function () {
    Route::get('/get.php', [PlaylistController::class, 'getPlaylist'])->name('iptv.playlist');
    Route::get('/player_api.php', [PlaylistController::class, 'playerApi'])->name('iptv.player-api');
});

// Stream proxy route with higher rate limit
Route::middleware('throttle:300,1')->group(function () {
    Route::get('/live/{username}/{password}/{streamId}', [PlaylistController::class, 'stream'])->name('iptv.stream');
});

Route::get('/{username}/{password}/{streamId}', [StreamController::class, 'proxy']);
