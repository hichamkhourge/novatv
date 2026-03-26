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

        // Stream the response transparently with proper error handling
        return response()->stream(function () use ($upstreamUrl) {
            // Use stream context with longer timeout and proper options
            $context = stream_context_create([
                'http' => [
                    'timeout' => 30,  // 30 second connection timeout
                    'ignore_errors' => false,
                    'user_agent' => request()->header('User-Agent', 'Mozilla/5.0'),
                ],
                'ssl' => [
                    'verify_peer' => false,
                    'verify_peer_name' => false,
                ],
            ]);

            $stream = @fopen($upstreamUrl, 'rb', false, $context);

            if ($stream === false) {
                abort(502, 'Failed to connect to upstream server');
            }

            // Set stream timeout for reading
            stream_set_timeout($stream, 30);

            // Use larger buffer for better video streaming performance (64KB)
            while (!feof($stream)) {
                $chunk = fread($stream, 65536);
                if ($chunk === false) {
                    break;
                }
                echo $chunk;
                flush();

                // Check for client disconnect
                if (connection_aborted()) {
                    break;
                }
            }

            fclose($stream);
        }, 200, [
            'Content-Type'  => 'video/mp2t',
            'Cache-Control' => 'no-cache',
            'X-Accel-Buffering' => 'no',  // Disable nginx buffering
        ]);
    }
}
