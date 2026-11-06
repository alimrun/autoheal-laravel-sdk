<?php

return [

    /*
    | AutoHeal stays off, and registers no hooks, until both a key and an endpoint are set.
    */

    'enabled' => (bool) env('AUTOHEAL_ENABLED', true),

    'key' => env('AUTOHEAL_KEY'),

    'endpoint' => env('AUTOHEAL_ENDPOINT'),

    'release' => env('AUTOHEAL_RELEASE'),

    'service' => env('AUTOHEAL_SERVICE'),

    /*
    | "http" sends after the response, job or command has finished. "queue" hands the events to a
    | queued job on the application's default queue connection instead.
    */

    'transport' => env('AUTOHEAL_TRANSPORT', 'http'),

    'log_level' => env('AUTOHEAL_LOG_LEVEL', 'error'),

    /*
    | Values under a key containing any of these names are replaced before sending. Keys are
    | compared case-insensitively with "-" and "_" ignored, matching the platform's redaction.
    */

    'redact_keys' => [
        'password',
        'passwd',
        'secret',
        'token',
        'api_key',
        'apikey',
        'authorization',
        'cookie',
        'session',
        'card_number',
        'cvv',
        'private_key',
        'access_key',
    ],

];
