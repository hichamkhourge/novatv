<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\PlaylistController;
use App\Http\Controllers\StreamController;
use App\Http\Controllers\HlsController;
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

// HLS stream routes with higher rate limit (for LG TV compatibility)
Route::middleware('throttle:300,1')->group(function () {
    Route::get('/hls/{username}/{password}/{streamId}.m3u8', [HlsController::class, 'playlist'])->name('iptv.hls.playlist');
    Route::get('/hls/{username}/{password}/{streamId}/{segment}', [HlsController::class, 'segment'])->name('iptv.hls.segment');
});

// Legacy stream proxy route with higher rate limit (kept for backward compatibility)
Route::middleware('throttle:300,1')->group(function () {
    Route::get('/live/{username}/{password}/{streamId}', [PlaylistController::class, 'stream'])->name('iptv.stream');
});

Route::get('/{username}/{password}/{streamId}', [StreamController::class, 'proxy']);
