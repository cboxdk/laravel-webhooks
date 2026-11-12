<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Cbox\Webhooks\Contracts\AttemptLog;
use Cbox\Webhooks\Contracts\Deliveries;
use Cbox\Webhooks\Contracts\EventLog;
use Cbox\Webhooks\Contracts\Publisher;
use Cbox\Webhooks\Contracts\ScopeResolver;
use Cbox\Webhooks\Enums\AttemptOutcome;
use Cbox\Webhooks\Enums\DeliveryStatus;
use Cbox\Webhooks\Exceptions\InvalidCursor;
use Cbox\Webhooks\Facades\Webhooks;
use Cbox\Webhooks\Http\Routes;
use Cbox\Webhooks\Support\Keyset;
use Cbox\Webhooks\Tests\Fixtures\RequestScopeResolver;
use Cbox\Webhooks\ValueObjects\AttemptFilter;
use Cbox\Webhooks\ValueObjects\DeliveryFilter;
use Cbox\Webhooks\ValueObjects\EventFilter;
use Cbox\Webhooks\ValueObjects\OutboundEvent;
use Cbox\Webhooks\ValueObjects\Page;
use Cbox\Webhooks\ValueObjects\Scope;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-10-30 12:00:00');
    $this->shop = Scope::of('shop', 'org_a');
    $this->desk = Scope::of('desk', 'org_b');
    $this->endpoint = $this->registerWebhookEndpoint($this->shop, 'https://receiver.test/shop', ['*'])->endpoint;
    $this->registerWebhookEndpoint($this->desk, 'https://receiver.test/desk', ['*']);
});

/** Publish `$count` events in a scope, `$perSecond` to a second, so timestamps tie. */
function publishSpread(Scope $scope, int $count, int $perSecond = 3, string $type = 'user.created'): void
{
    $start = CarbonImmutable::now();

    for ($i = 0; $i < $count; $i++) {
        CarbonImmutable::setTestNow($start->addSeconds(intdiv($i, $perSecond)));
        app(Publisher::class)->publish(new OutboundEvent($type, $scope, ['n' => $i]));
    }

    CarbonImmutable::setTestNow($start->addSeconds(intdiv($count, $perSecond) + 1));
}

/**
 * Walk every page and return the ids in the order they came.
 *
 * @param  Closure(?string): Page<object>  $fetch
 * @return list<string>
 */
function walk(Closure $fetch, int $maxPages = 50): array
{
    $ids = [];
    $cursor = null;

    do {
        $page = $fetch($cursor);

        foreach ($page->items as $item) {
            $ids[] = $item->id;
        }

        $cursor = $page->nextCursor;
    } while ($cursor !== null && --$maxPages > 0);

    return $ids;
}

it('pages through deliveries newest first without skipping or repeating a row, ties included', function (): void {
    publishSpread($this->shop, 11);
    publishSpread($this->desk, 4);

    $deliveries = app(Deliveries::class);
    $filter = new DeliveryFilter(limit: 4);

    $first = $deliveries->paginate($this->shop, $filter);

    expect($first->items)->toHaveCount(4)
        ->and($first->hasMore())->toBeTrue()
        ->and($first->items[0]->sequence)->toBe(11);

    $ids = walk(fn (?string $cursor): Page => $deliveries->paginate($this->shop, $filter, $cursor));

    expect($ids)->toHaveCount(11)
        ->and(array_unique($ids))->toHaveCount(11);

    // Newest first: the walk is the scope's rows by (created_at, id), descending.
    $sorted = $ids;
    rsort($sorted);
    expect($ids)->toBe($sorted);

    // The last page is exactly full or short, and says there is no more.
    $last = $deliveries->paginate($this->shop, new DeliveryFilter(limit: 11));
    expect($last->items)->toHaveCount(11)->and($last->nextCursor)->toBeNull();
});

it('does not shift when rows arrive while a caller is paging', function (): void {
    publishSpread($this->shop, 6);

    $deliveries = app(Deliveries::class);
    $filter = new DeliveryFilter(limit: 3);

    $first = $deliveries->paginate($this->shop, $filter);

    // New rows land at the head; the cursor points past them.
    publishSpread($this->shop, 5);

    $second = $deliveries->paginate($this->shop, $filter, $first->nextCursor);

    $seen = array_map(fn ($d): int => $d->sequence, [...$first->items, ...$second->items]);

    expect($seen)->toBe([6, 5, 4, 3, 2, 1])
        ->and($second->nextCursor)->toBeNull();
});

it('filters deliveries by endpoint, event, status, type and a time range', function (): void {
    Http::fake(['receiver.test/shop' => Http::sequence()->push('', 500)->whenEmpty(Http::response('', 200))]);
    $this->defineWebhookEvent('order.shipped', 'order');

    $failed = app(Publisher::class)->publish(new OutboundEvent('user.created', $this->shop, ['n' => 0]));
    CarbonImmutable::setTestNow('2026-10-30 12:10:00');
    app(Publisher::class)->publish(new OutboundEvent('order.shipped', $this->shop, ['n' => 1]));
    CarbonImmutable::setTestNow('2026-10-30 12:20:00');
    app(Publisher::class)->publish(new OutboundEvent('user.created', $this->shop, ['n' => 2]));

    $deliveries = app(Deliveries::class);
    $count = fn (DeliveryFilter $filter): int => count($deliveries->paginate($this->shop, $filter)->items);

    expect($count(new DeliveryFilter(eventId: $failed->id)))->toBe(1)
        ->and($count(new DeliveryFilter(status: DeliveryStatus::Failed)))->toBe(1)
        ->and($count(new DeliveryFilter(type: 'order.shipped')))->toBe(1)
        ->and($count(new DeliveryFilter(endpointId: $this->endpoint->id)))->toBe(3)
        ->and($count(new DeliveryFilter(endpointId: 'nope')))->toBe(0)
        ->and($count(new DeliveryFilter(since: CarbonImmutable::parse('2026-10-30 12:10:00'))))->toBe(2)
        ->and($count(new DeliveryFilter(until: CarbonImmutable::parse('2026-10-30 12:10:00'))))->toBe(2)
        ->and($count(new DeliveryFilter(since: CarbonImmutable::parse('2026-10-30 12:05:00'), until: CarbonImmutable::parse('2026-10-30 12:15:00'))))->toBe(1)
        // list() takes the same filter, so the two cannot drift.
        ->and($deliveries->list($this->shop, new DeliveryFilter(until: CarbonImmutable::parse('2026-10-30 12:10:00'))))->toHaveCount(2);
});

it('keeps a cursor inside the scope it is used in and refuses one it did not issue', function (): void {
    publishSpread($this->shop, 5);
    publishSpread($this->desk, 5);

    $deliveries = app(Deliveries::class);
    $shopCursor = $deliveries->paginate($this->shop, new DeliveryFilter(limit: 2))->nextCursor;

    // A cursor is a position. Used in another scope it still returns only that scope's rows.
    $page = $deliveries->paginate($this->desk, new DeliveryFilter(limit: 50), $shopCursor);

    foreach ($page->items as $delivery) {
        expect($delivery->scope->equals($this->desk))->toBeTrue();
    }

    expect(fn () => $deliveries->paginate($this->shop, new DeliveryFilter, 'not-a-cursor'))->toThrow(InvalidCursor::class)
        ->and(fn () => $deliveries->paginate($this->shop, new DeliveryFilter, base64_encode('{"t":"yesterday","i":"x"}')))->toThrow(InvalidCursor::class)
        ->and(fn () => $deliveries->paginate($this->shop, new DeliveryFilter, base64_encode('{"t":"2026-10-30T12:00:00.000000Z"}')))->toThrow(InvalidCursor::class)
        ->and(Keyset::decode(Keyset::encode(CarbonImmutable::parse('2026-10-30 12:00:00'), '01ABC'))[1])->toBe('01ABC')
        // An empty cursor is the first page, and the page size is clamped.
        ->and($deliveries->paginate($this->shop, new DeliveryFilter(limit: 0), '')->items)->toHaveCount(1);
});

it('looks an event up by id in its scope and pages through a scope\'s events by type and time', function (): void {
    $this->defineWebhookEvent('order.shipped', 'order');
    $first = app(Publisher::class)->publish(new OutboundEvent('user.created', $this->shop, ['n' => 0], 'key-0', CarbonImmutable::parse('2026-10-29 08:00:00')));
    publishSpread($this->shop, 7);
    publishSpread($this->shop, 2, type: 'order.shipped');
    publishSpread($this->desk, 3);

    $events = Webhooks::events();

    $found = $events->find($this->shop, $first->id);

    expect($found?->type)->toBe('user.created')
        ->and($found?->payload)->toBe(['n' => 0])
        ->and($found?->idempotencyKey)->toBe('key-0')
        ->and($found?->scope->equals($this->shop))->toBeTrue()
        ->and($found?->occurredAt?->toDateTimeString())->toBe('2026-10-29 08:00:00')
        ->and($found?->toArray()['occurred_at'])->toBe('2026-10-29T08:00:00+00:00')
        ->and($events->find($this->desk, $first->id))->toBeNull()
        ->and($events->find($this->shop, 'missing'))->toBeNull();

    $ids = walk(fn (?string $cursor): Page => $events->paginate($this->shop, new EventFilter(limit: 4), $cursor));

    expect($ids)->toHaveCount(10)
        ->and(array_unique($ids))->toHaveCount(10)
        // Ordered by when it occurred, not by when it was recorded: the backdated one is last.
        ->and($ids[9])->toBe($first->id)
        ->and($events->paginate($this->shop, new EventFilter(type: 'order.shipped'))->items)->toHaveCount(2)
        ->and($events->paginate($this->shop, new EventFilter(until: CarbonImmutable::parse('2026-10-29 23:59:59')))->items)->toHaveCount(1)
        ->and($events->paginate($this->shop, new EventFilter(since: CarbonImmutable::parse('2026-10-30 00:00:00')))->items)->toHaveCount(9)
        ->and($events->paginate($this->desk, new EventFilter)->items)->toHaveCount(3);
});

it('pages through a scope\'s attempts, filtered by endpoint, delivery, outcome and time', function (): void {
    Http::fake(['receiver.test/shop' => Http::sequence()->push('', 500)->push('', 500)->whenEmpty(Http::response('', 200))]);
    publishSpread($this->shop, 7);
    publishSpread($this->desk, 2);

    $attempts = Webhooks::attempts();

    $ids = walk(fn (?string $cursor): Page => $attempts->paginate($this->shop, new AttemptFilter(limit: 3), $cursor));
    $failed = $attempts->paginate($this->shop, new AttemptFilter(outcome: AttemptOutcome::HttpError));

    expect($ids)->toHaveCount(7)
        ->and(array_unique($ids))->toHaveCount(7)
        ->and($failed->items)->toHaveCount(2)
        ->and($failed->items[0]->scope->equals($this->shop))->toBeTrue()
        ->and($attempts->paginate($this->shop, new AttemptFilter(deliveryId: $failed->items[0]->deliveryId))->items)->toHaveCount(1)
        ->and($attempts->paginate($this->shop, new AttemptFilter(endpointId: $this->endpoint->id))->items)->toHaveCount(7)
        ->and($attempts->paginate($this->shop, new AttemptFilter(since: CarbonImmutable::parse('2026-10-30 12:00:02')))->items)->toHaveCount(1)
        ->and($attempts->paginate($this->shop, new AttemptFilter(until: CarbonImmutable::parse('2026-10-30 12:00:00')))->items)->toHaveCount(3)
        ->and(app(AttemptLog::class)->paginate($this->desk, new AttemptFilter)->items)->toHaveCount(2)
        ->and(app(EventLog::class)->paginate(Scope::of('nobody'), new EventFilter)->isEmpty())->toBeTrue();
});

it('serves the paginated listings through the management API, per caller', function (): void {
    Routes::register(app(Router::class), ['prefix' => 'api/webhooks']);
    app()->bind(ScopeResolver::class, RequestScopeResolver::class);

    publishSpread($this->shop, 5);
    publishSpread($this->desk, 2);

    $asShop = ['X-Org' => 'shop', 'X-Partition' => 'org_a'];
    $asDesk = ['X-Org' => 'desk', 'X-Partition' => 'org_b'];

    $first = $this->getJson('/api/webhooks/deliveries?limit=2', $asShop)->assertOk()->assertJsonCount(2, 'data');
    $cursor = $first->json('next_cursor');

    expect($cursor)->toBeString();

    $this->getJson('/api/webhooks/deliveries?limit=2&cursor='.$cursor, $asShop)->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('data.0.sequence', 3);
    $this->getJson('/api/webhooks/deliveries?limit=50', $asShop)->assertOk()->assertJsonCount(5, 'data')->assertJsonPath('next_cursor', null);
    $this->getJson('/api/webhooks/deliveries?cursor=garbage', $asShop)->assertStatus(422)->assertJsonPath('error', 'validation')->assertJsonStructure(['errors' => ['cursor']]);

    $this->getJson('/api/webhooks/attempts?limit=3', $asShop)->assertOk()->assertJsonCount(3, 'data')->assertJsonPath('data.0.scope.key', 'shop');
    $this->getJson('/api/webhooks/attempts?outcome=nonsense', $asShop)->assertStatus(422);
    $this->getJson('/api/webhooks/attempts?cursor=garbage', $asShop)->assertStatus(422);

    $events = $this->getJson('/api/webhooks/events?type=user.created&limit=4', $asShop)->assertOk()->assertJsonCount(4, 'data');
    $eventId = $events->json('data.0.id');

    $this->getJson('/api/webhooks/events?cursor='.$events->json('next_cursor'), $asShop)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('next_cursor', null);
    $this->getJson('/api/webhooks/events?cursor=garbage', $asShop)->assertStatus(422);
    $this->getJson('/api/webhooks/events/'.$eventId, $asShop)->assertOk()->assertJsonPath('data.type', 'user.created');
    $this->getJson('/api/webhooks/events/'.$eventId, $asDesk)->assertNotFound();
    $this->getJson('/api/webhooks/deliveries?event_id='.$eventId, $asShop)->assertOk()->assertJsonCount(1, 'data');
    $this->getJson('/api/webhooks/events', $asDesk)->assertOk()->assertJsonCount(2, 'data');
});
