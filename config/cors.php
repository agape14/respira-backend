<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may configure your settings for cross-origin resource sharing
    | or "CORS". This determines what cross-origin operations may execute
    | in web browsers. You are free to adjust these settings as needed.
    |
    | To learn more: https://developer.mozilla.org/en-US/docs/Web/HTTP/CORS
    |
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    'allowed_origins' => [
        // Desarrollo local
        'http://localhost:5173',
        'http://localhost:3000',
        'http://localhost:8000',
        'http://127.0.0.1:5173',
        'http://127.0.0.1:3000',

        // Producción - IP directa con puerto 84
        'http://172.17.16.16:84',
        'http://172.17.16.16',

        // Producción - Subdominio
        'http://respira-test.cmp.org.pe:84',
        'http://respira-test.cmp.org.pe',
        'https://respira-test.cmp.org.pe:84',
        'https://respira-test.cmp.org.pe',
        'https://respira-test.cmp.org.pe',
    ],

    'allowed_origins_patterns' => [
        // Permite cualquier IP en el rango 172.17.16.x con cualquier puerto
        '/^http:\/\/172\.17\.16\.\d+(?::\d+)?$/',
        '/^https?:\/\/.*\.cmp\.org\.pe(?::\d+)?$/',
    ],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => true,

];

