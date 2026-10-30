---
title: Signing
description: How every delivery is signed, what the receiver checks, and how rotation and timestamps interact.
weight: 63
---

# Signing

Every attempt is signed afresh with the endpoint's current secret and the current time
by `cboxdk/laravel-webhook-signature`.

## `cbox`

```
X-Cbox-Signature: t=1761825600,v1=<hex HMAC-SHA256 over "t.body">
X-Cbox-Timestamp: 1761825600
```

The secret is 64 hex characters. The receiver recomputes the HMAC with the timestamp
and the raw body, compares in constant time, and rejects timestamps outside its
tolerance.

## `standard-webhooks`

```
webhook-id: <delivery id>
webhook-timestamp: 1761825600
webhook-signature: v1,<base64 HMAC-SHA256 over "id.timestamp.body">
```

The secret is `whsec_` + base64. The id is the delivery id for a single delivery and
the batch id for a batch; a receiver can deduplicate on it.

## What is signed

The exact bytes of the body. Receivers must verify the raw body, not a re-encoded
parse. The package's own receiver does.

## Rotation

After `rotateSecret()` the new secret signs immediately; the previous one is valid for
the overlap so a receiver configured with both keeps verifying. See
[Secret rotation](../cookbook/secret-rotation.md).

## Why not a shared static header

A static `Authorization` header can be set per endpoint and is sent, but it proves
only that the sender knows the header; it does not bind the body, the time or the
delivery. The signature does all three.
