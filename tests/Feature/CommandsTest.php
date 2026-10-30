<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Cbox\Webhooks\Contracts\Publisher;
use Cbox\Webhooks\Models\WebhookDelivery;
use Cbox\Webhooks\ValueObjects\OutboundEvent;
use Cbox\Webhooks\ValueObjects\Scope;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-10-30 12:00:00');
    $this->scope = Scope::of('org_1', 'env_1');
});

it('prints the catalog as a table and as markdown', function (): void {
    $this->artisan('webhooks:catalog')->expectsOutputToContain('user.created')->assertSuccessful();
    $this->artisan('webhooks:catalog --markdown')->expectsOutputToContain('| `user.renamed` | legacy → `user.updated` | Legacy. |')->assertSuccessful();
});

it('prints the status overview and endpoint tables', function (): void {
    Http::fake(['receiver.test/*' => Http::response('', 500)]);
    $this->registerWebhookEndpoint($this->scope, 'https://receiver.test/hooks', ['user.created']);
    app(Publisher::class)->publish(new OutboundEvent('user.created', $this->scope, []));

    $this->artisan('webhooks:status --endpoints')->expectsOutputToContain('Failed, retrying (24h)')->assertSuccessful();
    $this->artisan('webhooks:status --json')->expectsOutputToContain('"failed_24h": 1')->assertSuccessful();
});

it('lists endpoints and deliveries with filters', function (): void {
    Http::fake(['receiver.test/*' => Http::response('', 500)]);
    $registered = $this->registerWebhookEndpoint($this->scope, 'https://receiver.test/hooks', ['user.created']);
    app(Publisher::class)->publish(new OutboundEvent('user.created', $this->scope, []));

    $this->artisan('webhooks:endpoints --scope=env_1/org_1')->expectsOutputToContain($registered->endpoint->id)->assertSuccessful();
    $this->artisan('webhooks:endpoints --scope=*/org_1')->expectsOutputToContain('No endpoints match.')->assertSuccessful();
    $this->artisan('webhooks:deliveries --status=failed --since="-1 hour" --endpoint='.$registered->endpoint->id)->expectsOutputToContain('user.created')->assertSuccessful();
    $this->artisan('webhooks:deliveries --status=bogus')->assertExitCode(2);
    $this->artisan('webhooks:deliveries --status=delivered')->expectsOutputToContain('No deliveries match.')->assertSuccessful();
});

it('retries, replays, sweeps and prunes from the console', function (): void {
    Http::fake(['receiver.test/*' => Http::sequence()->push('', 500)->push('', 200)->push('', 200)]);
    $registered = $this->registerWebhookEndpoint($this->scope, 'https://receiver.test/hooks', ['user.created']);
    app(Publisher::class)->publish(new OutboundEvent('user.created', $this->scope, []));
    $delivery = WebhookDelivery::query()->firstOrFail();

    $this->artisan('webhooks:retry '.$delivery->id)->expectsOutputToContain('queued')->assertSuccessful();
    expect($delivery->refresh()->status->value)->toBe('delivered');
    $this->artisan('webhooks:retry '.$delivery->id)->assertFailed();
    $this->artisan('webhooks:retry nope')->assertFailed();

    $this->artisan('webhooks:replay '.$registered->endpoint->id.' --since="2026-10-30 00:00:00" --type=user.created')->expectsOutputToContain('1 deliveries re-created')->assertSuccessful();
    $this->artisan('webhooks:replay '.$registered->endpoint->id)->assertExitCode(2);
    $this->artisan('webhooks:replay nope --since=yesterday')->assertFailed();

    $this->artisan('webhooks:sweep')->expectsOutputToContain('Retries queued')->assertSuccessful();
    $this->artisan('webhooks:prune')->expectsOutputToContain('Deliveries removed')->assertSuccessful();
});

it('registers the sweep and prune on the scheduler unless turned off', function (): void {
    $schedule = app(Schedule::class);
    $commands = array_map(static fn ($e): string => (string) $e->command, $schedule->events());

    expect(implode(' ', $commands))->toContain('webhooks:sweep')->toContain('webhooks:prune');
});
