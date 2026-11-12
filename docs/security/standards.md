---
title: Standards
description: The RFCs and conventions the package implements, and where it deliberately deviates.
weight: 64
---

# Standards

| Area | Follows |
| --- | --- |
| `Retry-After` | RFC 9110 §10.2.3: delay-seconds or an HTTP-date |
| 429 handling | RFC 6585: honoured, never counted as a receiver failure |
| 410 Gone | RFC 9110 §15.5.11: treated as a permanent condition; the endpoint is disabled |
| Idempotency | `Idempotency-Key` header carrying the delivery id, per the IETF draft's semantics (a key identifies one request's intent) |
| Signing | `standard-webhooks` as specified by its public specification; `cbox` is HMAC-SHA256 over `timestamp.body` |
| Trace propagation | W3C Trace Context `traceparent` on outbound requests, continued on inbound |
| Identifiers | ULIDs for every row; `webhook-id` carries one |
| Timestamps | ISO 8601 with offset in the envelope; Unix seconds in signature headers |
| JSON | `application/json`, UTF-8; response excerpts scrubbed to valid UTF-8 |
| CloudEvents | 1.0, structured content mode, JSON event format and JSON batch format, for endpoints on the `cloudevents` envelope. Binary mode is not offered. Checked by the package's own tests against the required attributes, not by an external conformance suite |

Deliberate deviations:

- Redirects (3xx) are not followed, whatever the method, because the target of a
  redirect was never validated.
- A 408 counts as a receiver failure; the receiver closed the request on its side.
- Batches have no per-item acknowledgement; the whole response applies to every item.
