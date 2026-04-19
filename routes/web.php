<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/debug-logs', function () {
    $results = [];
    $urls = [
        'Zazy' => 'http://live.zazytv.com/4498985795/2776382679/3166893',
        // Example Ugeen string from logs
        'Ugeen' => 'http://ugeen.example.com/54416.ts' 
    ];

    foreach ($urls as $name => $url) {
        $ch = curl_init($url);
        $buffer = '';
        curl_setopt_array($ch, [
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_USERAGENT      => 'VLC/3.0.20 LibVLC/3.0.20',
            CURLOPT_BUFFERSIZE     => 65536,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_WRITEFUNCTION  => function ($curl, $data) use (&$buffer): int {
                $buffer .= $data;
                // Abort after 2KB
                if (strlen($buffer) > 2048) {
                    return 0; // abort
                }
                return strlen($data);
            }
        ]);
        curl_exec($ch);
        $info = curl_getinfo($ch);
        $error = curl_error($ch);
        curl_close($ch);

        $results[$name] = [
            'effective_url' => $info['url'],
            'http_code' => $info['http_code'],
            'total_time' => $info['total_time'],
            'error' => $error,
            'bytes_downloaded' => strlen($buffer)
        ];
    }
    
    // Also attach last 50 lines of logs as a sanity check
    $logPath = storage_path('logs/laravel.log');
    $logSnippet = file_exists($logPath) ? implode("", array_slice(file($logPath), -50)) : "No logs";

    return response()->json([
        'curl_tests' => $results,
        'logs' => $logSnippet
    ]);
});

// All IPTV/Xtream API routes have been moved to routes/xtream.php
