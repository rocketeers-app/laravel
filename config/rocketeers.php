<?php

return [
    'api_token' => env('ROCKETEERS_API_TOKEN'),

    'environments' => [
        'production',
    ],

    /*
    |--------------------------------------------------------------------------
    | Horizon
    |--------------------------------------------------------------------------
    |
    | `enabled` lets Rocketeers reach the full Horizon dashboard with the API
    | token as a bearer token.
    |
    | The remaining keys drive the read-only stats endpoint the Rocketeers
    | dashboard polls from the browser. The secret is shared with the dashboard
    | and never travels to the browser: the dashboard signs a short-lived URL
    | with it and the browser only ever carries that signature. By convention
    | the secret is this environment's Rocketeers id.
    |
    | Leave the secret empty to keep the stats endpoint closed.
    |
    */

    'horizon' => [
        'enabled' => env('ROCKETEERS_HORIZON_ACCESS', true),
        'secret' => env('ROCKETEERS_HORIZON_SECRET'),
        'origin' => env('ROCKETEERS_HORIZON_ORIGIN', 'https://app.rocketeersapp.com'),
        'ttl' => (int) env('ROCKETEERS_HORIZON_TTL', 300),
    ],

    'sensitive_fields' => [
        'password',
        'password_confirmation',
        'token',
        'secret',
        'credit_card',
        'card_number',
        'cvv',
        'ssn',
        'authorization',
    ],
];
