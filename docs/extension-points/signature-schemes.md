---
title: Signature schemes
description: How outbound signing uses cboxdk/laravel-webhook-signature, and how to offer another scheme to endpoint owners.
weight: 55
---

# Signature schemes

Signing is delegated to `cboxdk/laravel-webhook-signature`. An endpoint's `scheme`
names one of that package's schemes; the engine asks the scheme to sign the body with
the endpoint's secret and a fresh timestamp on every attempt.

Shipped and offered to endpoint owners: `cbox` and `standard-webhooks`. A scheme must
be able to *sign*, not only verify, to be offered; verify-only schemes are refused with
`InvalidEndpoint::schemeCannotSign`.

To offer another scheme, register it with the signature package (`Webhooks::extend()`
there), add it to the `in:` rule your API accepts (the shipped `StoreEndpointRequest`
lists the two shipped ones; publish and extend it, or validate before calling the
registry), and make sure `Endpoints\SecretMinter` produces a secret in the form the
scheme expects (it mints `whsec_` base64 for `standard-webhooks` and 64 hex characters
for everything else).

The `Delivery\Signer` sets `webhook-id` to the delivery (or batch) id before signing so
schemes that bind the id into the signature get the right one.
