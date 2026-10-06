<?php

return [
    'device_key_pepper' => env('DEVICE_KEY_PEPPER', 'change-me-dev-only'),

    'ingest' => [
        'max_batch' => (int) env('INGEST_MAX_BATCH', 500),
        'future_tolerance_s' => (int) env('INGEST_FUTURE_TOLERANCE_S', 300),
        'max_readings_per_packet' => 32,
        'min_ts' => 1577836800, // 2020-01-01 00:00:00 UTC
    ],

    'readings' => [
        'max_points' => (int) env('READINGS_MAX_POINTS', 2000),
    ],

    'connectivity' => [
        'online_s' => (int) env('CONNECTIVITY_ONLINE_S', 300),
        'offline_s' => (int) env('CONNECTIVITY_OFFLINE_S', 900),
    ],

    'credential_grace_hours' => 24,

    'seed_device_keys' => env('SEED_DEVICE_KEYS', ''),
];
