---
title: SSRF
description: Endpoint URLs are attacker-controlled. How cboxdk/laravel-ssrf is applied at registration and right before every send.
weight: 62
---

# SSRF

An endpoint URL is the classic server-side request forgery vector: a customer registers
`https://169.254.169.254/` or a hostname that resolves to your database and your worker
makes the request with your network position.

The package applies `cboxdk/laravel-ssrf` twice:

1. **At registration and update.** `UrlGuard::assertSafe()` resolves the host and
   refuses loopback, private, link-local, multicast and reserved ranges, non-https
   schemes (unless `webhooks.allow_http`), userinfo in the URL, and unusual ports as
   that package configures. The management API reports it as a validation error on
   `url`.
2. **Right before every request.** `UrlGuard::pinnedOptions()` resolves the host again
   and pins the connection to the resolved address, so a record that changed to a
   private address after registration (DNS rebinding) is caught. A refusal here is the
   `blocked_url` outcome: the delivery retries on the schedule without counting against
   the endpoint, since the owner may fix DNS.

Redirects are never followed: a public URL that 302s to an internal one gets a failed
attempt, not a request.

The `allow_http` switch exists for a local receiver in development. Leave it off in
production; an http endpoint sends signed payloads in clear.
