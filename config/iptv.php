<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Stream Proxy Configuration
    |--------------------------------------------------------------------------
    |
    | These settings control how the IPTV stream proxy handles connections
    | to upstream providers and pre-buffering behavior.
    |
    */

    'stream' => [
        /**
         * Pre-buffer timeout in seconds
         *
         * How long to wait for MPEGTS data before streaming to client.
         * Longer timeout = better chance of instant playback without format detection delay.
         *
         * Default: 15 seconds (optimized for UGEEN and similar providers)
         */
        'prebuffer_timeout' => env('STREAM_PREBUFFER_TIMEOUT', 15),

        /**
         * Redirect resolution timeout in seconds
         *
         * Timeout when following redirects to get final CDN URL.
         *
         * Default: 5 seconds
         */
        'redirect_timeout' => env('STREAM_REDIRECT_TIMEOUT', 5),

        /**
         * Redirect connection timeout in seconds
         *
         * Connection timeout when following redirects.
         *
         * Default: 3 seconds
         */
        'redirect_connect_timeout' => env('STREAM_REDIRECT_CONNECT_TIMEOUT', 3),

        /**
         * Stream connection timeout in seconds
         *
         * How long to wait for initial connection to upstream provider.
         * Can be overridden by provider-specific settings.
         *
         * Default: 15 seconds (conservative), 30 seconds (aggressive mode)
         */
        'connection_timeout' => env('STREAM_CONNECTION_TIMEOUT', 15),

        /**
         * Minimum MPEGTS packets to buffer
         *
         * Number of MPEGTS packets (188 bytes each) to buffer before streaming.
         * 7 packets = 1316 bytes (includes PAT/PMT tables for proper format detection)
         *
         * Default: 7 packets
         */
        'min_mpegts_packets' => env('STREAM_MIN_MPEGTS_PACKETS', 7),
    ],

    /*
    |--------------------------------------------------------------------------
    | Provider-Specific Configuration
    |--------------------------------------------------------------------------
    |
    | Special handling for specific IPTV providers that require custom settings.
    |
    */

    'providers' => [
        /**
         * ZAZY Provider Configuration
         *
         * ZAZY uses load balancers that require cookie persistence across redirects.
         *
         * Fix modes:
         * - 'conservative': Enhanced cookie handling, standard timeouts
         * - 'aggressive': Enhanced cookies + extended connection timeout (30s)
         * - 'auto': Automatically detect based on provider URL
         */
        'zazy' => [
            'fix_mode' => env('ZAZY_FIX_MODE', 'conservative'),

            'detection_patterns' => [
                '172.110.220.61', // ZAZY direct IP
                'zazy',           // Username/password patterns
            ],

            'conservative' => [
                'connection_timeout' => 20,
                'use_persistent_cookies' => true,
                'max_redirects' => 10,
            ],

            'aggressive' => [
                'connection_timeout' => 30,
                'use_persistent_cookies' => true,
                'max_redirects' => 15,
                'follow_location' => true,
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Source Sync Configuration
    |--------------------------------------------------------------------------
    |
    | Periodic source re-import keeps channel stream IDs fresh, especially for
    | Xtream providers that rotate IDs over time.
    |
    */

    'sources' => [
        'auto_sync_enabled' => env('IPTV_SOURCE_AUTO_SYNC_ENABLED', true),
        'auto_sync_stale_minutes' => env('IPTV_SOURCE_AUTO_SYNC_STALE_MINUTES', 180),
        'auto_sync_batch_size' => env('IPTV_SOURCE_AUTO_SYNC_BATCH_SIZE', 2),
    ],

    /*
    |--------------------------------------------------------------------------
    | Logging Configuration
    |--------------------------------------------------------------------------
    |
    | Enable detailed logging for debugging provider issues.
    |
    */

    'logging' => [
        /**
         * Enable connection diagnostics logging
         *
         * Logs: timeouts, redirects, response codes, connection events
         */
        'connection_diagnostics' => env('IPTV_LOG_CONNECTIONS', true),

        /**
         * Enable pre-buffer metrics logging
         *
         * Logs: bytes received, warmup duration, buffering performance
         */
        'prebuffer_metrics' => env('IPTV_LOG_PREBUFFER', true),

        /**
         * Enable provider-specific logging
         *
         * Logs: cookie handling, headers, provider detection, special configurations
         */
        'provider_handling' => env('IPTV_LOG_PROVIDERS', true),

        /**
         * Log channel to use
         */
        'channel' => env('IPTV_LOG_CHANNEL', 'stack'),
    ],

];
