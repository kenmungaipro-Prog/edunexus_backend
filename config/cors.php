<?php

// ============================================================
// config/cors.php
// ============================================================

// config/cors.php
return [
    'paths' => ['api/*', 'sanctum/csrf-cookie'],
    'allowed_methods' => ['*'],
    'allowed_origins' => [
        'http://localhost:5173', // Force allow your Vite dev port
        'http://127.0.0.1:5173',
        'https://eternity-ladybug-wiry.ngrok-free.dev',
    ],
    'allowed_origins_patterns' => [],
    'allowed_headers' => ['*'],
    'exposed_headers' => [],
    'max_age' => 0,
    'supports_credentials' => true,
];

