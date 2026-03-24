<?php

use App\Http\Controllers\PlaylistController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// IPTV API Routes with rate limiting
Route::middleware('throttle:60,1')->group(function () {
    Route::get('/get.php', [PlaylistController::class, 'getPlaylist'])->name('iptv.playlist');
    Route::get('/player_api.php', [PlaylistController::class, 'playerApi'])->name('iptv.player-api');
});

// Stream proxy route with higher rate limit
Route::middleware('throttle:300,1')->group(function () {
    Route::get('/live/{username}/{password}/{streamId}', [PlaylistController::class, 'stream'])->name('iptv.stream');
});
