# Changelog

All notable changes to `rocketeers-laravel` will be documented in this file

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
