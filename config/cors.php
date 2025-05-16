<?php

return [
    'paths' => ['api/*', 'sanctum/csrf-cookie'], // Include sanctum if using Laravel Sanctum
    'allowed_methods' => ['*'],
    'allowed_origins' => ['http://localhost:5173'], // Explicitly allow your Vite frontend
    'allowed_origins_patterns' => [],
    'allowed_headers' => ['*'],
    'exposed_headers' => [],
    'max_age' => 0,
    'supports_credentials' => true, // Enable if using Authorization headers
];
