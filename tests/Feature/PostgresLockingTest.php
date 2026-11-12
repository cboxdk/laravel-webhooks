<?php

declare(strict_types=1);

use Cbox\Webhooks\Contracts\Publisher;
use Cbox\Webhooks\Enums\EndpointStatus;
use Cbox\Webhooks\Models\WebhookDelivery;
use Cbox\Webhooks\Models\WebhookEndpoint;
use Cbox\Webhooks\ValueObjects\OutboundEvent;
use Cbox\Webhooks\ValueObjects\Scope;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * What SQLite cannot show: a second connection. The suite runs inside one
 * transaction per test, so the endpoint is written and locked from a second
 * connection that commits for real, and removed again once the test's own
 * transaction has rolled back.
 */
it('makes a second publisher wait for the endpoint row, so two never stamp the same sequence', function (): void {
    config()->set('database.connections.other', config('database.connections.testing'));
    $other = DB::connection('other');

    $endpoint = (new WebhookEndpoint)->setConnection('other');
    $endpoint->fill([
        'partition' => 'env_1',
        'scope_key' => 'org_lock',
        'url' => 'https://receiver.test/hooks',
        'event_types' => ['user.created'],
        'secret' => 'whsec_test',
        'status' => EndpointStatus::Active,
        'last_sequence' => 41,
    ]);
    $endpoint->save();

    $this->beforeApplicationDestroyed(static function () use ($other, $endpoint): void {
        // The test's transaction still holds the row; let go of it before the delete waits on it.
        DB::connection()->rollBack();
        $other->table($endpoint->getTable())->where('id', $endpoint->id)->delete();
        $other->disconnect();
    });

    // The other publisher: mid fan-out, holding the row.
    $other->beginTransaction();
    $other->table($endpoint->getTable())->where('id', $endpoint->id)->lockForUpdate()->first();

    $event = new OutboundEvent('user.created', Scope::of('org_lock', 'env_1'), ['id' => 'usr_1']);

    DB::statement("SET LOCAL lock_timeout = '200ms'");

    expect(fn () => app(Publisher::class)->publish($event))->toThrow(QueryException::class, 'lock timeout');

    // The failed publish rolled back to its savepoint: no event, no delivery, and
    // the surrounding transaction is still usable.
    expect(WebhookDelivery::query()->where('endpoint_id', $endpoint->id)->count())->toBe(0);

    $other->table($endpoint->getTable())->where('id', $endpoint->id)->update(['last_sequence' => 42]);
    $other->commit();

    $published = app(Publisher::class)->publish($event);

    expect($published->deliveries)->toBe(1)
        ->and(WebhookDelivery::query()->where('endpoint_id', $endpoint->id)->value('sequence'))->toBe(43);
})->skip(fn (): bool => DB::connection()->getDriverName() !== 'pgsql', 'Needs a second connection: PostgreSQL only.');

it('reports a repeated idempotency key as a duplicate without poisoning the surrounding transaction', function (): void {
    $scope = Scope::of('org_1', 'env_1');
    $this->registerWebhookEndpoint($scope, 'https://receiver.test/hooks', ['user.created']);

    DB::transaction(function () use ($scope): void {
        $first = app(Publisher::class)->publish(new OutboundEvent('user.created', $scope, ['id' => 'usr_1'], 'key-1'));
        $again = app(Publisher::class)->publish(new OutboundEvent('user.created', $scope, ['id' => 'usr_1'], 'key-1'));

        // On PostgreSQL a unique violation aborts the transaction unless it was
        // taken inside a savepoint; this write proves the transaction survived.
        $after = app(Publisher::class)->publish(new OutboundEvent('user.created', $scope, ['id' => 'usr_2'], 'key-2'));

        expect($again->duplicate)->toBeTrue()
            ->and($again->id)->toBe($first->id)
            ->and($after->duplicate)->toBeFalse();
    });

    expect(WebhookDelivery::query()->count())->toBe(2);
});
