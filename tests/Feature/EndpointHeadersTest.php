<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Cbox\Webhooks\Contracts\Endpoints;
use Cbox\Webhooks\Contracts\Publisher;
use Cbox\Webhooks\Contracts\SecretEncrypter;
use Cbox\Webhooks\Exceptions\InvalidEndpoint;
use Cbox\Webhooks\Models\WebhookEndpoint;
use Cbox\Webhooks\ValueObjects\Endpoint;
use Cbox\Webhooks\ValueObjects\EndpointChanges;
use Cbox\Webhooks\ValueObjects\NewEndpoint;
use Cbox\Webhooks\ValueObjects\OutboundEvent;
use Cbox\Webhooks\ValueObjects\Scope;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-10-30 12:00:00');
    $this->scope = Scope::of('shop', 'org_a');
    $this->fakeDnsForUrl('https://receiver.test/hooks');
});

/** @return array<string, mixed> */
function storedHeaders(): array
{
    $raw = DB::table('webhook_endpoints')->value('headers');

    return is_string($raw) ? (array) json_decode($raw, true) : [];
}

it('seals header values at rest and sends them in the clear', function (): void {
    $registered = $this->registerWebhookEndpoint($this->scope, 'https://receiver.test/hooks', ['user.created'], headers: ['Authorization' => 'Bearer ek_secret_value']);

    $stored = storedHeaders();

    expect(array_keys($stored))->toBe(['Authorization'])
        ->and($stored['Authorization'])->not->toContain('ek_secret_value')
        ->and($stored['Authorization'])->toStartWith('sealed:')
        ->and($registered->endpoint->headers)->toBe(['Authorization' => 'Bearer ek_secret_value'])
        ->and(app(Endpoints::class)->find($this->scope, $registered->endpoint->id)?->headers)->toBe(['Authorization' => 'Bearer ek_secret_value']);

    app(Publisher::class)->publish(new OutboundEvent('user.created', $this->scope, ['id' => 'usr_1']));

    $request = Http::recorded()->first()[0];
    expect($request)->toBeInstanceOf(Request::class)
        ->and($request->header('Authorization'))->toBe(['Bearer ek_secret_value']);
});

it('seals through the bound encrypter', function (): void {
    app()->instance(SecretEncrypter::class, new class implements SecretEncrypter
    {
        public function encrypt(string $secret): string
        {
            return strrev($secret);
        }

        public function decrypt(string $stored): string
        {
            return strrev($stored);
        }
    });

    $registered = $this->registerWebhookEndpoint($this->scope, 'https://receiver.test/hooks', ['user.created'], headers: ['X-Token' => 'abc']);

    expect(storedHeaders())->toBe(['X-Token' => 'sealed:cba'])
        ->and($registered->endpoint->headers)->toBe(['X-Token' => 'abc']);
});

it('still reads a value stored unsealed before 0.3', function (): void {
    $registered = $this->registerWebhookEndpoint($this->scope, 'https://receiver.test/hooks', ['user.created']);
    DB::table('webhook_endpoints')->update(['headers' => json_encode(['X-Legacy' => 'plain'])]);

    expect(app(Endpoints::class)->find($this->scope, $registered->endpoint->id)?->headers)->toBe(['X-Legacy' => 'plain']);
});

it('seals the plaintext rows an upgrade finds', function (): void {
    $this->registerWebhookEndpoint($this->scope, 'https://receiver.test/hooks', ['user.created']);
    DB::table('webhook_endpoints')->update(['headers' => json_encode(['X-Legacy' => 'plain'])]);

    $migration = require __DIR__.'/../../database/migrations/2026_11_13_000001_seal_webhook_endpoint_headers.php';
    $migration->up();

    expect(storedHeaders()['X-Legacy'])->toStartWith('sealed:')
        ->and(WebhookEndpoint::query()->firstOrFail()->headers)->toBe(['X-Legacy' => 'plain']);

    // Running it twice does not seal twice.
    $migration->up();
    expect(WebhookEndpoint::query()->firstOrFail()->headers)->toBe(['X-Legacy' => 'plain']);

    $migration->down();
    expect(storedHeaders())->toBe(['X-Legacy' => 'plain']);
});

it('shows header values masked when serialised', function (): void {
    $endpoint = new Endpoint(headers: ['Authorization' => 'Bearer ek_3kQ9x7LmP2', 'X-Short' => 'ab']);

    expect($endpoint->toArray()['headers'])->toBe(['Authorization' => 'Bearer…', 'X-Short' => '…'])
        ->and($endpoint->maskedHeaders())->toBe(['Authorization' => 'Bearer…', 'X-Short' => '…']);
});

it('refuses reserved, malformed and oversized headers', function (array $headers): void {
    expect(fn () => app(Endpoints::class)->register($this->scope, new NewEndpoint('https://receiver.test/hooks', ['user.created'], headers: $headers)))
        ->toThrow(InvalidEndpoint::class);
})->with([
    'Host' => [['host' => 'x']],
    'Content-Encoding' => [['Content-Encoding' => 'gzip']],
    'User-Agent' => [['USER-AGENT' => 'x']],
    'a webhook- name' => [['Webhook-Custom' => 'x']],
    'an x-webhook- name' => [['X-Webhook-Anything' => 'x']],
    'an x-cbox- name' => [['x-cbox-tenant' => 'x']],
    'a ce- name' => [['ce-source' => 'x']],
    'not a token' => [['Bad Name' => 'x']],
    'an empty name' => [['' => 'x']],
    'CR LF in the value' => [['X-Inject' => "a\r\nHost: evil"]],
    'a value over 1000' => [['X-Long' => str_repeat('a', 1001)]],
    'eleven headers' => [array_combine(array_map(static fn (int $i): string => 'X-H'.$i, range(1, 11)), array_fill(0, 11, 'v'))],
]);

it('accepts ten ordinary headers', function (): void {
    $headers = array_combine(array_map(static fn (int $i): string => 'X-H'.$i, range(1, 10)), array_fill(0, 10, str_repeat('v', 1000)));

    $registered = app(Endpoints::class)->register($this->scope, new NewEndpoint('https://receiver.test/hooks', ['user.created'], headers: $headers));

    expect($registered->endpoint->headers)->toBe($headers);
});

it('refuses a reserved header on update and clears headers with an empty map', function (): void {
    $registered = $this->registerWebhookEndpoint($this->scope, 'https://receiver.test/hooks', ['user.created'], headers: ['X-A' => '1']);
    $id = $registered->endpoint->id;

    expect(fn () => app(Endpoints::class)->update($this->scope, $id, new EndpointChanges(headers: ['ce-id' => 'x'])))->toThrow(InvalidEndpoint::class)
        ->and(app(Endpoints::class)->update($this->scope, $id, new EndpointChanges(headers: []))?->headers)->toBe([])
        ->and(DB::table('webhook_endpoints')->value('headers'))->toBeNull();
});
