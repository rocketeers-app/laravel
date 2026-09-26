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

    /*
    |--------------------------------------------------------------------------
    | Redaction
    |--------------------------------------------------------------------------
    |
    | Reports are scrubbed by Rocketeers\Redactor before they leave the process.
    | It already covers passwords, tokens, secrets, keys, signatures, cookies,
    | sessions and card data, matched as a substring of the field name — so
    | "secret" also covers "client_secret". List anything extra your app uses
    | here; the built-in list is never replaced.
    |
    | `redact_logs` adds the same scrubbing to every log channel, not just this
    | package's, because Laravel merges the ambient Context into each record.
    |
    */

    'sensitive_fields' => [
        //
    ],

    'redact_logs' => env('ROCKETEERS_REDACT_LOGS', true),
];
