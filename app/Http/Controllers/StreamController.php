<?php

namespace App\Http\Controllers;

use App\Models\IptvUser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class StreamController extends Controller
{
    public function proxy(string $username, string $password, string $streamId)
    {
        // Validate the user
        $user = IptvUser::where('username', $username)
            ->where('password', $password)
            ->where('is_active', true)
            ->first();

        if (!$user) {
            abort(403);
        }

        // Build upstream URL
        $upstreamUrl = "http://ugeen.live:8080/Ugeen_VIPR5WrbA/pnasWG/{$streamId}";

        // Stream the response transparently
        $response = Http::timeout(10)->get($upstreamUrl);

        return response()->stream(function () use ($upstreamUrl) {
            $stream = fopen($upstreamUrl, 'rb');
            if ($stream) {
                while (!feof($stream)) {
                    echo fread($stream, 8192);
                    flush();
                }
                fclose($stream);
            }
        }, 200, [
            'Content-Type'  => 'video/mp2t',
            'Cache-Control' => 'no-cache',
        ]);
    }
}
