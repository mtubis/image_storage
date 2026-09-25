<?php

declare(strict_types=1);

return [

    /*
    | The SPA is deployed on its own origin and is the API's only browser client.
    | An unset FRONTEND_URL allows no origin at all rather than falling back to `*`.
    | Methods and headers are what the SPA sends; a new request header must be added here.
    */

    'paths' => ['api/*'],

    'allowed_methods' => ['GET', 'POST', 'DELETE'],

    // An Origin header never has a trailing slash, a configured URL easily does.
    'allowed_origins' => array_filter(
        [rtrim((string) env('FRONTEND_URL', ''), '/')],
        static fn (string $origin): bool => $origin !== '',
    ),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['Accept', 'Content-Type'],

    // The SPA downloads through a plain link, which doesn't need it; exposed so a script-driven
    // client (fetch/XHR) can still read the original filename.
    'exposed_headers' => ['Content-Disposition'],

    // Chrome's upper bound; preflights for the same request are not repeated for 2 hours.
    'max_age' => 7200,

    // No authentication, so no cookies to send cross-origin.
    'supports_credentials' => false,

];
