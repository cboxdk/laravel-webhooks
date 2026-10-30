# Security Policy

## Supported versions

The latest minor of the current major receives security fixes.

## Reporting a vulnerability

Please do not open a public issue. Email [sn@cbox.dk](mailto:sn@cbox.dk) with a
description and, if possible, a proof of concept. Reports are handled on a best-effort
basis; there is no fixed response time.

Areas of particular interest for this package:

- Endpoint URLs are attacker-controlled. They pass `cboxdk/laravel-ssrf` at
  registration and are pinned again right before every request; redirects are never
  followed. A way to make a worker request a private address is a vulnerability.
- Tenant isolation: every lookup takes a `Scope` and must answer `null` for another
  scope's id. A query that reads across scopes outside the sweep and the unscoped
  monitor is a vulnerability.
- Secrets: encrypted at rest, returned once on registration and rotation, never in
  `GET` responses, events, logs or exceptions.
- Static headers: the reserved list in `Endpoints\EndpointPolicy` must keep a
  customer from setting signature, framing or engine headers.
- The receiving middleware verifies the signature before parsing anything.

See [docs/security/threat-model.md](docs/security/threat-model.md) for the full model
and what the package deliberately leaves to the host.
