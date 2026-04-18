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

// Stream proxy — PHP fallback only (Nginx handles /live/* directly via proxy_pass)
Route::get('/live/{username}/{password}/{stream_id}', [IptvController::class, 'streamProxy'])
    ->where('stream_id', '.*\.(ts|m3u8)')
    ->name('iptv.stream');

// ── Nginx auth_request endpoint ──────────────────────────────────────────────
// Nginx calls this INTERNALLY via auth_request before proxy_pass-ing the stream.
// It receives stream info via custom FastCGI headers (X-Stream-Username, etc.)
// and returns either:
//   200 + X-Upstream-* headers  → Nginx proxies to that upstream URL
//   401/403/404                 → Nginx returns the matching error page
Route::get('/api/auth/stream', [IptvController::class, 'authStream'])
    ->name('iptv.auth_stream');
