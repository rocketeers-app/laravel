<?php

return [
    'api_token' => env('ROCKETEERS_API_TOKEN'),

    'environments' => [
        'production',
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
