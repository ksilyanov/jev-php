<?php

return [
    // Transport that serves Jev. Switching it is an .env change; app code does not change.
    'driver' => env('JEV_DRIVER', 'cloudflare'),

    // Empty = the driver's default (cloudflare: typesafe/jev — Cloudflare serves only the latest version).
    'model' => env('JEV_MODEL') ?: null,

    // Seconds for the whole call, retries and waits included.
    'timeout' => (float) env('JEV_TIMEOUT', 30),
    'retries' => (int) env('JEV_RETRIES', 2),

    'drivers' => [
        'cloudflare' => [
            'account_id' => env('JEV_CLOUDFLARE_ACCOUNT_ID', ''),
            'api_token' => env('JEV_CLOUDFLARE_API_TOKEN', ''),
            // Optional AI Gateway id (per-app analytics/limits). Keep gateway-level retries off.
            'gateway_id' => env('JEV_CLOUDFLARE_GATEWAY_ID') ?: null,
        ],
    ],
];
