---
title: Requirements
description: What composer.json enforces: PHP, Laravel, extensions and the direct dependencies.
weight: 3
---

# Requirements

Taken from `composer.json`; nothing here is a recommendation, it is what the resolver
enforces.

| Requirement | Constraint |
| --- | --- |
| PHP | `^8.4` |
| Laravel (`illuminate/*`) | `^12.0 \|\| ^13.0` |
| `ext-json` | `*` |
| `cboxdk/laravel-ssrf` | `^1.5` |
| `cboxdk/laravel-webhook-signature` | `^0.1` |
| `guzzlehttp/psr7` | `^2.7 \|\| ^3.0` |
| `psr/log` | `^3.0` |
| `symfony/http-foundation` | `^7.0 \|\| ^8.0` |

Optional: `cboxdk/laravel-telemetry ^3.8` for metrics, spans and the `traceparent`
propagation. Without it the engine's metrics and tracing seams are bound to no-ops.

Runtime needs: a relational database (SQLite, MySQL/MariaDB or PostgreSQL; the suite
runs on SQLite and PostgreSQL, not on MySQL), a cache store for the per-minute rate limiter and inbound gap detection,
queue workers, and the scheduler (`schedule:run`) for the sweep and the prune.

CI runs the suite on PHP 8.4 and 8.5 against Laravel 12 and 13 on SQLite, and again on
PostgreSQL 18 (PHP 8.4 with Laravel 12, PHP 8.5 with Laravel 13).
