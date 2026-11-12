---
title: Erasure
description: Forget everything the engine holds for a scope, and what retention removes on its own.
weight: 48
---

# Erasure

```php
app(\Cbox\Webhooks\Contracts\Erasure::class)->forget(Scope::of('org_1'));   // rows removed
```

Removes the scope's events (and their payloads), deliveries and attempts (and their
response excerpts). Attempts carry their delivery's scope, so they are found by an
index even when the delivery is already gone. It is a hard delete in chunks, inside the scope only: a
partition-wide endpoint's deliveries for other owners are not touched. Endpoints are
configuration, not data about a person; delete them through `Endpoints::delete()` when
the customer leaves. Inbound ledger rows are keyed by the sender's scope and are not
part of an owner's erasure; prune them by retention or delete by hand.

## One person inside a scope

Name the person when you publish, with your own id for them (not an address):

```php
$publisher->publish(new OutboundEvent('order.paid', $scope, $payload, subject: 'contact_42'));
```

and forget them later:

```php
app(\Cbox\Webhooks\Contracts\SubjectErasure::class)->forgetSubject($scope, 'contact_42');   // rows removed
```

That removes their events in that scope with the deliveries and attempts of those
events. The subject is stored with the event and never sent to a receiver. An event
published without a subject is not found: it goes with the scope's erasure or with
retention.

## Retention

`webhooks:prune` (daily) removes, per `webhooks.retention` (or per scope, when the host
binds [`RetentionPolicies`](../extension-points/retention.md)):

| Rows | After |
| --- | --- |
| delivered deliveries and their attempts | `delivered_days` (30) |
| exhausted and cancelled deliveries and their attempts | `failed_days` (90) |
| events, by `occurred_at` | `events_days` (30): the replay horizon |
| inbound ledger rows | `inbound_days` (30) |

A delivery whose event was pruned before it could be sent (a retry of an exhausted
delivery weeks later) is exhausted with reason `event_pruned`; keep `events_days` at
or above `failed_days` if you want every retry to stay possible.

Payloads are stored as published. If they carry personal data, keep the windows short
and publish references rather than records where you can.
