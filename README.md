# Rocketeers for Laravel

[![Latest Version on Packagist](https://img.shields.io/packagist/v/rocketeers-app/rocketeers-laravel.svg?style=flat-square)](https://packagist.org/packages/rocketeers-app/rocketeers-laravel)
[![Build Status](https://img.shields.io/travis/rocketeers-app/rocketeers-laravel/master.svg?style=flat-square)](https://travis-ci.org/rocketeers-app/rocketeers-laravel)
[![Quality Score](https://img.shields.io/scrutinizer/g/rocketeers-app/rocketeers-laravel.svg?style=flat-square)](https://scrutinizer-ci.com/g/rocketeers-app/rocketeers-laravel)
[![Total Downloads](https://img.shields.io/packagist/dt/rocketeers-app/rocketeers-laravel.svg?style=flat-square)](https://packagist.org/packages/rocketeers-app/rocketeers-laravel)

Laravel integration package with Rocketeers app.

## Installation

You can install this package via Composer:

```bash
composer require rocketeers-app/rocketeers-laravel
```

You don't need to change your logging configuration. The package registers a `rocketeers` log channel and adds it to your default `stack` channel, so your normal logging keeps working and exceptions are also reported to Rocketeers.

This only happens when your default log channel is a `stack`, which is the Laravel default:

```php
'default' => env('LOG_CHANNEL', 'stack'),
```

If you use another default channel, add `rocketeers` to a stack yourself. To change the channel's settings, define your own `rocketeers` channel in `config/logging.php`. The package leaves it as it is.

Publish the configuration file:

```bash
php artisan vendor:publish --provider="Rocketeers\Laravel\RocketeersLoggerServiceProvider" --tag="config"
```

This will create a `config/rocketeers.php` file with the following defaults:

```php
<?php

return [
    'api_token' => env('ROCKETEERS_API_TOKEN'),

    'environments' => [
        'production',
    ],

    'horizon' => [
        'enabled' => env('ROCKETEERS_HORIZON_ACCESS', true),
        'secret' => env('ROCKETEERS_HORIZON_SECRET'),
        'origin' => env('ROCKETEERS_HORIZON_ORIGIN', 'https://app.rocketeersapp.com'),
        'ttl' => (int) env('ROCKETEERS_HORIZON_TTL', 300),
    ],

    'sensitive_fields' => [
        //
    ],

    'redact_logs' => env('ROCKETEERS_REDACT_LOGS', true),

    'api_url' => env('ROCKETEERS_API_URL'),

    'ingest' => [
        'enabled' => env('ROCKETEERS_INGEST', true),
        'uri' => env('ROCKETEERS_INGEST_URI'),
        'outgoing_requests' => [
            'enabled' => env('ROCKETEERS_OUTGOING_REQUESTS', true),
            'sample_rate' => (float) env('ROCKETEERS_OUTGOING_REQUESTS_SAMPLE_RATE', 1.0),
        ],
    ],
];
```

You don't have to publish the file again after upgrading: new keys get their defaults from the package.

## Redaction

Reports are scrubbed by `Rocketeers\Redactor` before they leave the process. Every path into the
API goes through `Rocketeers::report()`, so the Monolog handler, the queued-job listener and any
report you build by hand are all covered.

Field names are matched as a **substring**, lower-cased with dashes normalised to underscores, so
one entry covers a family of names — `secret` also covers `client_secret`, and `token` also covers
`refresh_token` and `X-Api-Key` (via `api_key`). Out of the box that list covers passwords, tokens,
secrets, API and private keys, signatures, cookies, sessions and card data.

Credentials that carry no field name to recognise them by are matched by shape: private key blocks,
`Authorization: Bearer`/`Basic` headers, `MYSQL_PWD=`, `--password=`, `sshpass -p`, SQL
`IDENTIFIED BY`, and credential-shaped query parameters in any URL (a signed URL's `signature`, an
OAuth `code`). A string that holds JSON — a queued job's raw body, for instance — is decoded and
walked rather than matched as one blob.

Add anything your own app uses to `sensitive_fields`; the built-in list is never replaced:

```php
'sensitive_fields' => ['pincode', 'bsn'],
```

### Log channels

`redact_logs` puts the same scrubbing on **every** log channel, not just this package's. Laravel
merges the ambient `Context` into each record's `extra`, so a credential put there once would
otherwise reach your log file and your Slack channel as well as Rocketeers. Set
`ROCKETEERS_REDACT_LOGS=false` to leave your other channels alone.

Add the `ROCKETEERS_API_TOKEN` to your `.env` file.

## Horizon access

When [Laravel Horizon](https://laravel.com/docs/horizon) is installed, this package lets Rocketeers reach the Horizon dashboard and its API by sending your `ROCKETEERS_API_TOKEN` as a bearer token:

```
Authorization: Bearer <ROCKETEERS_API_TOKEN>
```

No extra setup is needed. The package wraps the authorization callback that is already registered — your own `viewHorizon` gate and any `Horizon::auth()` callback keep working exactly as before, this only adds the token as an extra way in.

Turn it off by setting `ROCKETEERS_HORIZON_ACCESS=false` in your `.env` file, or by setting `rocketeers.horizon.enabled` to `false`.

Note that Horizon runs in the `web` middleware group, so its `POST` endpoints (retrying jobs, for example) are still subject to CSRF protection. Reading queue metrics over the API works out of the box; if you also want Rocketeers to perform actions, exclude `horizon/*` from CSRF verification in your application.

## Horizon monitoring

The Rocketeers dashboard shows every Horizon instance in one table, refreshing itself
from the browser. To keep that fast it polls each application directly rather than
proxying through Rocketeers, so this package exposes one read-only endpoint:

```
GET /rocketeers/horizon/stats?expires=<timestamp>&signature=<hmac>
```

It returns only aggregate counts — `status`, `processes`, `jobsPerMinute`,
`failedJobs`, `recentJobs` and `wait`. Job payloads and Horizon's action endpoints
are never reachable through it, so this is deliberately narrower than the bearer
token access described above.

Set the shared secret in your `.env` file:

```
ROCKETEERS_HORIZON_SECRET=<your Rocketeers environment id>
```

Rocketeers writes this for you when it provisions the environment. For an existing
one, use **Insert › Horizon monitoring** in the Rocketeers env editor.

The dashboard signs a short-lived URL with that secret and hands only the signature
to the browser; requests without a valid, unexpired signature get a `403`. Leaving
the secret empty keeps the endpoint closed.

The endpoint sits outside the `web` middleware group — no session, no cookies, no
CSRF token — and answers with an `Access-Control-Allow-Origin` header for the
dashboard. Point that elsewhere with `ROCKETEERS_HORIZON_ORIGIN`, and change the
signature lifetime with `ROCKETEERS_HORIZON_TTL` (seconds, default 300).

## Rocketeers agent

Without the agent, every error is posted to Rocketeers while the request or job that hit it waits
for the answer. With the agent running, the app hands errors to a local daemon in well under a
millisecond and goes on; the agent uploads them in compressed batches in the background, and
retries when the API is briefly unreachable. The agent also enables recording the outgoing requests
your app makes with Laravel's HTTP client.

```bash
php artisan rocketeers:agent
```

**Not running the agent changes nothing.** Errors are then posted directly, exactly as before, no
outgoing requests are recorded, and nothing is written to disk.

### Running it

Run the agent as a daemon next to your queue workers, as the same user as PHP-FPM, one per server.
On Rocketeers, add it with **Create daemon › Rocketeers Agent**. With supervisor:

```ini
[program:rocketeers-agent]
command=php /var/www/example.com/artisan rocketeers:agent
user=www-data
autostart=true
autorestart=true
stopsignal=TERM
stopwaitsecs=15
redirect_stderr=true
stdout_logfile=/var/www/example.com/storage/logs/rocketeers-agent.log
```

- On a stop signal the agent removes its socket first, so the app sends errors directly from that
  moment, then uploads what it still holds for at most `--drain` seconds (default 5) and exits.
  This needs `ext-pcntl`; without it the agent warns at start and stops at once.
- `--max-memory=128` makes it stop gracefully above that many MB, for supervisor to restart it.
- `-v` logs a line of statistics every minute.
- Without a `ROCKETEERS_API_TOKEN`, with `ROCKETEERS_INGEST=false` or in an environment that is not
  listed in `rocketeers.environments`, the agent idles instead of exiting, so supervisor keeps it
  running without error.
- Only one agent runs per app: a second one waits until the first stops.
- Restart the agent after deploying and after changing `ROCKETEERS_API_TOKEN`; it refuses records
  from an app that uses another token than its own.

### Where the app finds it

The agent listens on a unix socket at `storage/framework/rocketeers.sock`, with the storage path
resolved through symlinks, so every release of a zero-downtime deploy finds the same socket. It is
created with mode `0660`: only the app's own user and group can write to it, and no port has to be
allocated per server.

Set `ROCKETEERS_INGEST_URI` for both the app and the agent to use another address:

```dotenv
# A socket path of more than 100 bytes, which unix sockets do not allow, e.g. a deep path on macOS:
ROCKETEERS_INGEST_URI=unix:///tmp/example-rocketeers.sock

# The agent in another container:
ROCKETEERS_INGEST_URI=tcp://rocketeers-agent:7625
```

In the agent's container, `php artisan rocketeers:agent --listen=tcp://0.0.0.0:7625` overrides the
address it listens on.

### Outgoing requests

With the agent running, a token set and the environment listed in `rocketeers.environments`,
each request your app sends through Laravel's HTTP client (`Http::get()` and friends, Laravel 10.14
or newer) is recorded with:

- the method, scheme, host and port;
- the path, without its query string, fragment or credentials, and with segments that look like
  tokens masked: a segment of 16 or more characters mixing letters and digits becomes `*****`, a
  Telegram-style `bot123:…` segment becomes `bot*****`, and UUIDs are kept;
- the status, or `timeout`, `dns`, `connect`, `tls` or `other` when no response arrived;
- the start time and duration, and the sizes of the request and response bodies in bytes;
- the request, job or command it was made from (route name or uri, job class, command name).

Headers, cookies, query strings and request or response bodies are **never** recorded: bodies are
not even read, their sizes come from the stream or the `Content-Length` header.

Opt a single call out with `Http::withoutRecording()`, which also chains
(`Http::withHeaders(...)->withoutRecording()->get(...)`) and holds through redirects. For code that
sends its own requests, such as a third-party package, wrap it instead:

```php
use Illuminate\Support\Facades\Http;
use Rocketeers\Laravel\Ingest\RecordOutgoingRequests;

Http::withoutRecording()->get('https://example.com/health');

RecordOutgoingRequests::withoutRecording(fn () => $favicons->fetch($domain));
```

The macro is always registered, so it is safe to call locally, in tests and with recording switched off.

Record only a share of your requests, jobs and commands with a sample rate between 0 and 1, or switch
the recording off:

```dotenv
ROCKETEERS_OUTGOING_REQUESTS_SAMPLE_RATE=0.25
ROCKETEERS_OUTGOING_REQUESTS=false
```

### When the agent cannot take an error

An error the agent does not accept (it is not running, does not answer within 250 milliseconds, its
queue is full, or Rocketeers rejected its token) is posted directly instead. Both ways carry the same
report id, so Rocketeers stores the error once. Outgoing requests the agent does not accept are
dropped. Set `ROCKETEERS_INGEST=false` to bypass the agent altogether.

## Testing the integration

To verify that error reporting is wired up correctly, run:

```bash
php artisan rocketeers:test
```

This posts a test error straight to the Rocketeers API and shows right away whether it arrived: the HTTP status Rocketeers answered with, and when it was rejected, its message and what to check (an unrecognised token, a token that may not report errors, a wrong `ROCKETEERS_API_URL`) or why the API could not be reached. The command exits with 1 when the error did not arrive, so it can run in a deploy script. It also checks that a `ROCKETEERS_API_TOKEN` is configured and warns when the current environment is not listed in `rocketeers.environments` (real errors would not be reported there).

It also tells whether the Rocketeers agent is running (and which version) and whether outgoing requests are being recorded, and if not, why. Use `--agent` to hand the test error to a running agent instead; the agent uploads it later, so the command cannot show whether it arrived.

### Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information what has changed recently.

For Laravel 10.x and up use `v2.0.0`.

For Laravel 9.x and below use `v1.0.0` or the `release/v1` branch.

## Contributing

Please see [CONTRIBUTING](CONTRIBUTING.md) for details.

### Security

If you discover any security related issues, please email mark@vaneijk.co instead of using the issue tracker.

## Credits

- [Mark van Eijk](https://github.com/markvaneijk)
- [All Contributors](../../contributors)

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
