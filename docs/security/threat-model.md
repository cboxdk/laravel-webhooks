---
title: Threat model
description: Attacker-controlled URLs, tenant boundaries, secrets at rest and in logs, replay, and the host's responsibilities.
weight: 61
---

# Threat model

## Assets

Endpoint secrets; the payloads you publish; the tenant boundary between scopes; your
workers' network position.

## Attacker-controlled input

**Endpoint URLs.** A customer can point an endpoint anywhere. See [SSRF](ssrf.md):
only public https hosts, validated at registration and pinned right before every
request, redirects not followed unless the endpoint opts in (then 307/308 only, every
hop guarded and pinned like the first URL), `http` off by default.

**Static headers.** A customer can set up to ten headers. Reserved ones (host, content
framing, the user agent, signature headers, the engine's own and the `webhook-`,
`x-webhook-`, `x-cbox-` and `ce-` prefixes) are refused so a header cannot forge a
signature or pose as the engine; names must be HTTP tokens and values cannot contain CR,
LF or NUL, so a header cannot smuggle another. Values are sealed at rest with the bound
`SecretEncrypter`, because they often hold the receiver's credentials, and shown masked
by `Endpoint::toArray()`.

**Transforms.** An endpoint's transform is an expression for the host's bound
`PayloadTransformer`; the package ships none and refuses every expression by default.
Choose a language that runs no code (a query language, not a template engine with
callbacks), because the endpoint owner writes the expression and it runs in your
worker.

**Inbound webhooks.** A request to a receiving route is unauthenticated until the
signature verifies; the middleware verifies before it parses. The body is bounded by
your web server. Delivery ids are deduplicated in a ledger, so a replayed request does
not run handlers twice. Timestamps are checked by the signature package against its
tolerance.

**Management API input.** Validated by form requests with the same policy the registry
applies; the scope comes from your resolver, never from the request.

## Tenant boundary

Every query carries a `Scope` value; ids from another scope answer `null`. Wide scopes
cannot be built from nulls and cannot be stored unless the operator opts in. The sweep
and the unscoped monitor are the only cross-scope readers.

## Secrets

Encrypted at rest through `Contracts\SecretEncrypter`: Laravel's encrypter and your
`APP_KEY` by default, or [a key of your choice](../extension-points/secret-encrypter.md). Returned once on registration
and rotation; never by `GET`, never in `Endpoint::toArray()`, never in an event or a log
line. The previous secret is kept only for the overlap and cleared by the sweep.
Rotating the key requires re-encrypting the columns, or an encrypter that still reads
the previous key.

## Payloads

Stored as published, for replay, until `retention.events_days`. Response excerpts from
receivers are stored (1000 bytes, scrubbed to valid UTF-8). Both can contain personal
data; retention and [erasure](../cookbook/erasure.md) are the controls.

## Denial of service

A slow receiver holds a worker for at most `connect_timeout + timeout` (15 s). The
breaker stops sending to a dead receiver after five failures; the per-endpoint rate
limit stops a customer's burst from monopolising workers; a dedicated queue stops
webhooks from starving the rest of the app. Payload size is capped at publish.

## What the package does not do

- Authenticate the management API. Put your own middleware on the routes.
- mTLS, OAuth or IP allow-listing towards receivers.
- Guarantee exactly-once delivery.
- Protect secrets from someone who holds both the database and the encryption key
  (`APP_KEY`, or the key your `SecretEncrypter` uses).
