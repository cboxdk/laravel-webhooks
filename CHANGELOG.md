# Changelog

All notable changes to this package are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [Unreleased]

### Added

- The outbox: `Contracts\Publisher` with `DatabasePublisher`, idempotency keys per
  scope, per-endpoint gapless sequences, after-commit unique jobs.
- The registry: `Contracts\Endpoints` with register/update/pause/resume/rotate
  secret/change scheme/delete/ping/replay/health, scoped by `ValueObjects\Scope`, wide
  endpoints only by opt-in, URL policy through `cboxdk/laravel-ssrf`.
- The delivery engine: conditional attempt claims, the retry schedule with jitter,
  `Retry-After` on 429, 410 disables, a per-endpoint circuit breaker with one probe and
  doubling cooldowns, per-minute rate limits, auto-disable after a window unreachable,
  batching by size or window, signing through `cboxdk/laravel-webhook-signature`.
- Housekeeping: the sweep (orphans, stranded rescue, due retries, batch flush,
  auto-disable, secret expiry), the pruner, `Contracts\Erasure`.
- Receiving: the `webhooks.receive` middleware, the inbound ledger, gap detection,
  `Contracts\Receivers`, queued or inline processing.
- The management API (`Http\Routes`), eight console commands, seventeen Laravel
  events, `Contracts\Monitor`, the telemetry adapters, `Testing\InteractsWithWebhooks`
  and `Testing\FakeWebhooks`.
