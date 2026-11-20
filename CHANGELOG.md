# Changelog

All notable changes to `rocketeers-laravel` will be documented in this file

## 2.25.0 - 2026-11-20

- Add `Http::withoutRecording()` to keep a single outgoing request out of the recording; it chains with the other `PendingRequest` methods and holds through redirects
- Add `RecordOutgoingRequests::withoutRecording(fn () => ...)` to keep every request sent inside the callback out of the recording, for code that builds its own requests; it nests and resumes recording after an exception
- The macro is registered whether or not ingest is active, so calling it never breaks locally, in tests or with recording switched off

## 2.24.0 - 2026-11-17

- Add the Rocketeers agent, `php artisan rocketeers:agent`: a local daemon without dependencies that takes errors from the app over a unix socket in `storage/framework` (or `ROCKETEERS_INGEST_URI`) and uploads them gzipped in batches to `/v1/ingest`, retrying with backoff when the API is unreachable
- Hand errors to the agent when it runs, and post them directly as before when it does not or does not accept them; both ways carry the same `report_id`, so an error is stored once
- Record the outgoing requests of Laravel's HTTP client (Laravel 10.14 or newer) while the agent runs: method, host, masked path, status or connection error, duration and sizes, never headers, bodies or query strings; sampled through `ROCKETEERS_OUTGOING_REQUESTS_SAMPLE_RATE`
- Add `rocketeers.api_url` (`ROCKETEERS_API_URL`) and `rocketeers.ingest`; no need to publish the config again
- `rocketeers:test` shows whether the agent runs, which way the test error went and whether outgoing requests are recorded, and gets a `--direct` option
- Without a running agent nothing changes: errors are posted with exactly the same payload, nothing is recorded and nothing is written
- Suggest `ext-pcntl`, which lets the agent finish its uploads when it is stopped
- Require `rocketeers-app/rocketeers-api-client` 2.24

## 2.23.7 - 2026-11-17

- Skip an exception thrown in a queued job when the exception handler would not report it, so `ShouldntReport` and `dontReport()` / `ignore()` now hold for jobs too instead of only for the log channel

## 2.22.0 - 2026-10-23

- Add `queues` to the `/rocketeers/horizon/stats` endpoint: per queue its length, wait and processes from Horizon's workload, and its jobs per minute and average runtime from Horizon's metrics, for Rocketeers queue autoscaling
- Queues processed by `queue:work` rather than a Horizon supervisor are included through Horizon's metrics, with their length read from the queue and no wait or process count
- Still no job payloads: the endpoint stays a whitelisted subset

## 2.21.0 - 2026-10-12

- Send the trace as frames: the throw site first, every frame with its own function, a path relative to `base_path()`, whether it is an application or a vendor frame, and the 29 lines of code around its line
- Stop sending the arguments of each frame
- Drop the code of vendor frames, then every frame past the 50th, when the trace would exceed 512 KB
- Require `rocketeers-app/rocketeers-api-client` 2.21, which redacts that code

## 2.20.0 - 2026-10-12

- Send the ambient Laravel `Context` and the log context (without the exception) as `context`

## 2.19.0 - 2026-09-29

- Register the `rocketeers` log channel and add it to the default `stack` channel automatically, so `config/logging.php` no longer needs changes

## 2.11.0 - 2026-09-08

- Redact credentials from reports using the shared `Rocketeers\Redactor`, replacing the exact-name field filter that missed `current_password`, `client_secret` and every other compound name
- Redact the log context, the ambient Laravel `Context`, the request URL and query string, the exception message and the stack trace, none of which were filtered before
- Add the same redaction to every log channel through `redact_logs`, so a credential in `Context` no longer reaches the log file or Slack either
- `sensitive_fields` is now additive on top of the built-in list rather than replacing it
- Require `rocketeers-app/rocketeers-api-client` ^1.3

## 2.10.0 - 2026-08-29

- Add a signed, read-only `/rocketeers/horizon/stats` endpoint for the Rocketeers Horizon monitor, configurable through `rocketeers.horizon.secret`, `rocketeers.horizon.origin` and `rocketeers.horizon.ttl`
- Test against Laravel 13 by allowing `orchestra/testbench` 11.x

## 2.9.0 - 2026-08-25

- Allow access to Laravel Horizon by sending the Rocketeers API token as a bearer token, configurable through `rocketeers.horizon.enabled`

## 2.8.0 - 2026-07-05

- Add `rocketeers:test` Artisan command to verify the error reporting integration

## 1.0.0 - 201X-XX-XX

- initial release
