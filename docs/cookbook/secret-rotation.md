---
title: Secret rotation
description: Rotate an endpoint's secret with an overlap window, and switch signature schemes.
weight: 45
---

# Secret rotation

```php
$rotated = Webhooks::endpoints()->rotateSecret(Scope::of('org_1'), $endpointId, overlapSeconds: 86_400);
$rotated->secret;                    // show once
$rotated->previousSecretExpiresAt;
```

From now on deliveries are signed with the new secret. The previous one is kept,
encrypted, until the overlap ends (`webhooks.secret_rotation.overlap_seconds` by
default), and the sweep clears it. `SecretRotated` fires.

The receiver verifies with either secret during the overlap: the signature package's
inbound config takes a list, and `cboxdk/laravel-webhook-signature` tries each. Tell
your customers to add the new secret first, then remove the old one after the window.

Rotation does not re-sign in-flight or retrying deliveries with the old secret: every
attempt is signed afresh at send time with the current secret.

## Changing the scheme

```php
$changed = Webhooks::endpoints()->changeScheme(Scope::of('org_1'), $endpointId, 'standard-webhooks');
$changed->secret;   // a fresh secret in the new scheme's form (whsec_…)
```

A scheme change is a hard cut: a new secret, no overlap, the previous secret dropped.
The receiver must switch verification at the same time; pause the endpoint around the
change if that is not instantaneous.
