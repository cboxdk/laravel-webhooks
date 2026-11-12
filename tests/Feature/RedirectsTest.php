<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Cbox\Webhooks\Contracts\Publisher;
use Cbox\Webhooks\Delivery\HttpTransport;
use Cbox\Webhooks\Enums\AttemptOutcome;
use Cbox\Webhooks\Enums\DeliveryStatus;
use Cbox\Webhooks\Models\WebhookAttempt;
use Cbox\Webhooks\Models\WebhookDelivery;
use Cbox\Webhooks\ValueObjects\OutboundEvent;
use Cbox\Webhooks\ValueObjects\Scope;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-10-30 12:00:00');
    $this->scope = Scope::of('shop', 'org_a');
    // Through the trait, so registering an endpoint later keeps these hosts.
    $this->fakeDnsForUrl('https://receiver.test/', '93.184.216.34');
    $this->fakeDnsForUrl('https://other.test/', '93.184.216.35');
    $this->fakeDnsForUrl('https://internal.test/', '10.0.0.5');
});

/** @return list<Request> */
function sentRequests(): array
{
    return array_values(array_map(static fn (array $pair): Request => $pair[0], Http::recorded()->all()));
}

function deliverOne(Scope $scope): WebhookDelivery
{
    app(Publisher::class)->publish(new OutboundEvent('user.created', $scope, ['id' => 'usr_1']));

    return WebhookDelivery::query()->firstOrFail();
}

it('follows a 307 and a 308 with the same method, body and signature', function (): void {
    Http::fake([
        'receiver.test/old' => Http::response('', 307, ['Location' => '/moved']),
        'receiver.test/moved' => Http::response('', 308, ['Location' => 'https://other.test/final']),
        'other.test/final' => Http::response('ok', 200),
    ]);

    $this->registerWebhookEndpoint($this->scope, 'https://receiver.test/old', ['user.created'], followRedirects: true);

    $delivery = deliverOne($this->scope);
    $requests = sentRequests();

    expect($delivery->status)->toBe(DeliveryStatus::Delivered)
        ->and(array_map(static fn (Request $r): string => $r->url(), $requests))->toBe(['https://receiver.test/old', 'https://receiver.test/moved', 'https://other.test/final'])
        ->and(array_unique(array_map(static fn (Request $r): string => $r->method(), $requests)))->toBe(['POST'])
        ->and(array_unique(array_map(static fn (Request $r): string => $r->body(), $requests)))->toHaveCount(1)
        ->and(array_unique(array_map(static fn (Request $r): string => $r->header('X-Cbox-Signature')[0] ?? '', $requests)))->toHaveCount(1)
        ->and($requests[2]->header('X-Cbox-Signature')[0] ?? '')->not->toBe('');
});

it('refuses a redirect when the endpoint does not follow them', function (): void {
    Http::fake(['receiver.test/old' => Http::response('', 307, ['Location' => 'https://other.test/final'])]);

    $this->registerWebhookEndpoint($this->scope, 'https://receiver.test/old', ['user.created']);

    $delivery = deliverOne($this->scope);

    expect($delivery->status)->toBe(DeliveryStatus::Failed)
        ->and($delivery->last_response_code)->toBe(307)
        ->and(sentRequests())->toHaveCount(1);
});

it('refuses 301, 302 and 303 even when following', function (int $status): void {
    Http::fake(['receiver.test/old' => Http::response('', $status, ['Location' => 'https://other.test/final'])]);

    $this->registerWebhookEndpoint($this->scope, 'https://receiver.test/old', ['user.created'], followRedirects: true);

    $delivery = deliverOne($this->scope);
    $attempt = WebhookAttempt::query()->firstOrFail();

    expect($delivery->status)->toBe(DeliveryStatus::Failed)
        ->and($attempt->response_code)->toBe($status)
        ->and($attempt->error)->toContain('redirect_refused')
        ->and(sentRequests())->toHaveCount(1);
})->with([301, 302, 303]);

it('stops after three hops', function (): void {
    Http::fake([
        'receiver.test/0' => Http::response('', 307, ['Location' => '/1']),
        'receiver.test/1' => Http::response('', 307, ['Location' => '/2']),
        'receiver.test/2' => Http::response('', 307, ['Location' => '/3']),
        'receiver.test/3' => Http::response('', 307, ['Location' => '/4']),
        'receiver.test/4' => Http::response('', 200),
    ]);

    $this->registerWebhookEndpoint($this->scope, 'https://receiver.test/0', ['user.created'], followRedirects: true);

    deliverOne($this->scope);

    expect(sentRequests())->toHaveCount(4)
        ->and(WebhookAttempt::query()->firstOrFail()->error)->toContain('too_many_redirects');
});

it('runs every hop through the SSRF guard', function (): void {
    Http::fake(['receiver.test/old' => Http::response('', 307, ['Location' => 'https://internal.test/admin'])]);

    $this->registerWebhookEndpoint($this->scope, 'https://receiver.test/old', ['user.created'], followRedirects: true);

    deliverOne($this->scope);
    $attempt = WebhookAttempt::query()->firstOrFail();

    expect(sentRequests())->toHaveCount(1)
        ->and($attempt->outcome)->toBe(AttemptOutcome::BlockedUrl);
});

it('refuses a hop to plain http when https is required', function (): void {
    Http::fake(['receiver.test/old' => Http::response('', 308, ['Location' => 'http://other.test/final'])]);

    $this->registerWebhookEndpoint($this->scope, 'https://receiver.test/old', ['user.created'], followRedirects: true);

    deliverOne($this->scope);

    expect(sentRequests())->toHaveCount(1)
        ->and(WebhookAttempt::query()->firstOrFail()->outcome)->toBe(AttemptOutcome::BlockedUrl);
});

it('fails a redirect without a Location', function (): void {
    Http::fake(['receiver.test/old' => Http::response('', 307)]);

    $this->registerWebhookEndpoint($this->scope, 'https://receiver.test/old', ['user.created'], followRedirects: true);

    deliverOne($this->scope);

    expect(WebhookAttempt::query()->firstOrFail()->error)->toContain('redirect_refused');
});

it('pins each hop to the address the guard resolved for it', function (): void {
    $pins = [];

    Http::fake(function (Request $request, array $options) use (&$pins) {
        $pins[$request->url()] = $options['curl'][CURLOPT_RESOLVE] ?? null;

        return str_contains($request->url(), 'receiver.test')
            ? Http::response('', 307, ['Location' => 'https://other.test/final'])
            : Http::response('', 200);
    });

    $result = app(HttpTransport::class)->send('https://receiver.test/old', '{}', [], followRedirects: true);

    expect($result->outcome)->toBe(AttemptOutcome::Success)
        ->and(implode(' ', (array) $pins['https://receiver.test/old']))->toContain('receiver.test:443:93.184.216.34')
        ->and(implode(' ', (array) $pins['https://other.test/final']))->toContain('other.test:443:93.184.216.35')
        ->and(implode(' ', (array) $pins['https://other.test/final']))->not->toContain('93.184.216.34');
});
