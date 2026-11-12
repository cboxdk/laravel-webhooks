<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Cbox\Webhooks\Contracts\Deliveries;
use Cbox\Webhooks\Contracts\Endpoints;
use Cbox\Webhooks\Contracts\Metrics;
use Cbox\Webhooks\Contracts\Publisher;
use Cbox\Webhooks\Contracts\RequestProfiles;
use Cbox\Webhooks\Contracts\SecretEncrypter;
use Cbox\Webhooks\Contracts\Tracing;
use Cbox\Webhooks\Endpoints\ApplicationSecretEncrypter;
use Cbox\Webhooks\Enums\DeliveryStatus;
use Cbox\Webhooks\Enums\SpanKind;
use Cbox\Webhooks\Models\WebhookAttempt;
use Cbox\Webhooks\Models\WebhookDelivery;
use Cbox\Webhooks\Models\WebhookEndpoint;
use Cbox\Webhooks\Models\WebhookEvent;
use Cbox\Webhooks\Models\WebhookInboundDelivery;
use Cbox\Webhooks\Monitoring\SpanContext;
use Cbox\Webhooks\ValueObjects\DeliveryFilter;
use Cbox\Webhooks\ValueObjects\Endpoint;
use Cbox\Webhooks\ValueObjects\OutboundEvent;
use Cbox\Webhooks\ValueObjects\RequestProfile;
use Cbox\Webhooks\ValueObjects\Scope;
use Cbox\WebhookSignature\Contracts\Webhooks as Signatures;
use Cbox\WebhookSignature\ValueObjects\WebhookMessage;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-10-30 12:00:00');
    $this->engage = Scope::of('engage', 'org_a');
    $this->status = Scope::of('status', 'org_b');
});

function lastRequestTo(string $url): Request
{
    $matching = array_values(array_filter(Http::recorded()->all(), static fn (array $pair): bool => $pair[0]->url() === $url));

    return $matching[count($matching) - 1][0];
}

// --- User agent and fixed headers -------------------------------------------------

it('sends the configured user agent and fixed headers to every endpoint by default', function (): void {
    config(['webhooks.http.user_agent' => 'Acme-Webhooks/2.0', 'webhooks.http.headers' => ['X-Sender' => 'acme', 'X-Region' => 'eu']]);
    $this->registerWebhookEndpoint($this->engage, 'https://receiver.test/hooks', ['user.created'], headers: ['X-Region' => 'customer-says-us']);

    app(Publisher::class)->publish(new OutboundEvent('user.created', $this->engage, ['id' => 1]));
    $request = lastRequestTo('https://receiver.test/hooks');

    expect($request->header('User-Agent')[0])->toBe('Acme-Webhooks/2.0')
        ->and($request->header('X-Sender')[0])->toBe('acme')
        // The endpoint's own static header wins over the host's fixed one.
        ->and($request->header('X-Region')[0])->toBe('customer-says-us');
});

it('lets the host choose the user agent and headers per endpoint, by scope or by metadata', function (): void {
    app()->bind(RequestProfiles::class, fn () => new class implements RequestProfiles
    {
        public function for(Endpoint $endpoint): RequestProfile
        {
            return match ($endpoint->scope->key) {
                'engage' => new RequestProfile('Cbox-Engage-Webhooks/1.0', [
                    'X-Product' => 'engage',
                    // An attempt to replace what the engine owns.
                    'X-Webhook-Event' => 'spoofed',
                    'X-Cbox-Signature' => 'spoofed',
                    'Idempotency-Key' => 'spoofed',
                ]),
                default => new RequestProfile,
            };
        }
    });

    $registered = $this->registerWebhookEndpoint($this->engage, 'https://receiver.test/engage', ['user.created']);
    $this->registerWebhookEndpoint($this->status, 'https://receiver.test/status', ['user.created']);

    app(Publisher::class)->publish(new OutboundEvent('user.created', $this->engage, ['id' => 1]));
    app(Publisher::class)->publish(new OutboundEvent('user.created', $this->status, ['id' => 2]));

    $engage = lastRequestTo('https://receiver.test/engage');
    $status = lastRequestTo('https://receiver.test/status');
    $delivery = WebhookDelivery::query()->where('scope_key', 'engage')->firstOrFail();

    expect($engage->header('User-Agent')[0])->toBe('Cbox-Engage-Webhooks/1.0')
        ->and($engage->header('X-Product')[0])->toBe('engage')
        ->and($engage->header('X-Webhook-Event'))->toBe(['user.created'])
        ->and($engage->header('Idempotency-Key'))->toBe([$delivery->id])
        ->and($status->header('User-Agent')[0])->toStartWith('Cbox-Webhooks/')
        ->and($status->hasHeader('X-Product'))->toBeFalse();

    // The signature header is the engine's: the request still verifies.
    $this->fakeWebhookEndpoint('inbound', 'cbox', $registered->secret);
    expect(app(Signatures::class)->verify('inbound', WebhookMessage::make($engage->body(), $engage->headers()))->scheme)->toBe('cbox');
});

// --- The key the secrets are stored under ------------------------------------------

it('stores secrets under the application key by default and reads rows 0.1 wrote', function (): void {
    expect(app(SecretEncrypter::class))->toBeInstanceOf(ApplicationSecretEncrypter::class);

    $registered = $this->registerWebhookEndpoint($this->engage, 'https://receiver.test/hooks', ['user.created']);
    $raw = DB::table('webhook_endpoints')->where('id', $registered->endpoint->id)->value('secret');

    // What the `encrypted` cast wrote in 0.1 is Crypt::encryptString; both directions agree.
    expect($raw)->not->toBe($registered->secret)
        ->and(Crypt::decryptString($raw))->toBe($registered->secret);

    DB::table('webhook_endpoints')->where('id', $registered->endpoint->id)->update(['secret' => Crypt::encryptString('whsec_from_0_1')]);

    expect(WebhookEndpoint::query()->firstOrFail()->secret)->toBe('whsec_from_0_1')
        ->and(WebhookEndpoint::query()->firstOrFail()->previous_secret)->toBeNull();
});

it('stores and reads secrets through the host\'s encrypter, rotation included', function (): void {
    app()->bind(SecretEncrypter::class, fn () => new class implements SecretEncrypter
    {
        public function encrypt(string $secret): string
        {
            return 'kms:v1:'.strrev(base64_encode($secret));
        }

        public function decrypt(string $stored): string
        {
            if (! str_starts_with($stored, 'kms:v1:')) {
                throw new RuntimeException('Not a value this key wrote.');
            }

            return (string) base64_decode(strrev(substr($stored, 7)), true);
        }
    });

    $registered = $this->registerWebhookEndpoint($this->engage, 'https://receiver.test/hooks', ['user.created']);
    $raw = DB::table('webhook_endpoints')->where('id', $registered->endpoint->id)->value('secret');

    expect($raw)->toStartWith('kms:v1:')
        ->and(fn () => Crypt::decryptString($raw))->toThrow(DecryptException::class)
        ->and(WebhookEndpoint::query()->firstOrFail()->secret)->toBe($registered->secret);

    // Signing reads the secret back through the same encrypter.
    app(Publisher::class)->publish(new OutboundEvent('user.created', $this->engage, ['id' => 1]));
    $request = lastRequestTo('https://receiver.test/hooks');
    $this->fakeWebhookEndpoint('inbound', 'cbox', $registered->secret);
    expect(app(Signatures::class)->verify('inbound', WebhookMessage::make($request->body(), $request->headers()))->scheme)->toBe('cbox');

    $rotated = app(Endpoints::class)->rotateSecret($this->engage, $registered->endpoint->id);
    $row = DB::table('webhook_endpoints')->where('id', $registered->endpoint->id)->first();
    $model = WebhookEndpoint::query()->firstOrFail();

    expect($row->previous_secret)->toStartWith('kms:v1:')
        ->and($model->previous_secret)->toBe($registered->secret)
        ->and($model->secret)->toBe($rotated?->secret);
});

// --- The database connection -------------------------------------------------------

it('keeps its tables on the configured connection, migrations included', function (): void {
    $files = glob(__DIR__.'/../../database/migrations/*.php');
    sort($files);

    expect((new WebhookEndpoint)->getConnectionName())->toBeNull()
        ->and((require $files[0])->getConnection())->toBeNull();

    // A second connection (on SQLite a second in-memory database), and a prefix
    // of its own so nothing can be mistaken for the default connection's tables.
    config()->set('database.connections.outbox', config('database.connections.testing'));
    config()->set('webhooks.connection', 'outbox');
    config()->set('webhooks.table_prefix', 'outbox_');

    $migrations = array_map(static fn (string $file): object => require $file, $files);

    $this->beforeApplicationDestroyed(static function () use ($migrations): void {
        DB::setDefaultConnection('outbox');

        foreach (array_reverse($migrations) as $migration) {
            $migration->down();
        }

        DB::setDefaultConnection('testing');
        DB::connection('outbox')->disconnect();
    });

    // What the migrator does: run each migration with its own connection as the default.
    foreach ($migrations as $migration) {
        expect($migration->getConnection())->toBe('outbox');

        DB::setDefaultConnection($migration->getConnection());
        $migration->up();
        DB::setDefaultConnection('testing');
    }

    foreach ([WebhookEndpoint::class, WebhookEvent::class, WebhookDelivery::class, WebhookAttempt::class, WebhookInboundDelivery::class] as $model) {
        expect((new $model)->getConnectionName())->toBe('outbox');
    }

    Http::fake(['receiver.test/*' => Http::sequence()->push('', 500)->whenEmpty(Http::response('', 200))]);
    $this->registerWebhookEndpoint($this->engage, 'https://receiver.test/hooks', ['user.created']);
    app(Publisher::class)->publish(new OutboundEvent('user.created', $this->engage, ['id' => 1], 'key-1'));
    $again = app(Publisher::class)->publish(new OutboundEvent('user.created', $this->engage, ['id' => 1], 'key-1'));

    CarbonImmutable::setTestNow('2026-10-30 12:00:10');
    $report = $this->deliverPendingWebhooks();

    $outbox = DB::connection('outbox');

    expect($again->duplicate)->toBeTrue()
        ->and($report->retriesQueued)->toBe(1)
        ->and($outbox->table('outbox_endpoints')->count())->toBe(1)
        ->and($outbox->table('outbox_events')->count())->toBe(1)
        ->and($outbox->table('outbox_attempts')->count())->toBe(2)
        ->and($outbox->table('outbox_deliveries')->where('status', DeliveryStatus::Delivered->value)->count())->toBe(1)
        ->and(app(Deliveries::class)->list($this->engage, new DeliveryFilter))->toHaveCount(1)
        // Nothing landed on the default connection.
        ->and(DB::connection('testing')->table('webhook_endpoints')->count())->toBe(0)
        ->and(DB::connection('testing')->table('webhook_deliveries')->count())->toBe(0);

    // A connection set on the instance still wins over the configured one.
    expect((new WebhookEndpoint)->setConnection('testing')->getConnectionName())->toBe('testing');
});

// --- The scope on spans, never on metrics ------------------------------------------

it('puts the scope on the publish and deliver spans only when asked, and never on a metric', function (): void {
    $spans = [];
    $tags = [];

    app()->instance(Tracing::class, new class($spans) implements Tracing
    {
        /** @param array<string, array<string, scalar|null>> $spans */
        public function __construct(private array &$spans) {}

        public function span(string $name, SpanKind $kind, array $attributes, Closure $callback): mixed
        {
            $this->spans[$name] = $attributes;

            return $callback(new SpanContext);
        }

        public function propagationHeaders(): array
        {
            return [];
        }

        public function continueTrace(?string $traceparent): void {}
    });

    app()->instance(Metrics::class, new class($tags) implements Metrics
    {
        /** @param list<scalar|null> $tags */
        public function __construct(private array &$tags) {}

        public function counter(string $name, int $by = 1, array $tags = []): void
        {
            array_push($this->tags, ...array_keys($tags), ...array_values($tags));
        }

        public function histogram(string $name, float $value, array $tags = []): void
        {
            array_push($this->tags, ...array_keys($tags), ...array_values($tags));
        }

        public function gauge(string $name, float $value, array $tags = []): void
        {
            array_push($this->tags, ...array_keys($tags), ...array_values($tags));
        }
    });

    $this->registerWebhookEndpoint($this->engage, 'https://receiver.test/hooks', ['user.created']);
    app(Publisher::class)->publish(new OutboundEvent('user.created', $this->engage, ['id' => 1]));

    expect($spans['webhooks.publish'])->not->toHaveKey('webhook.scope.key')
        ->and($spans['webhooks.deliver'])->not->toHaveKey('webhook.scope.partition');

    config(['webhooks.telemetry.scope_attributes' => true]);
    app(Publisher::class)->publish(new OutboundEvent('user.created', $this->engage, ['id' => 2]));

    expect($spans['webhooks.publish']['webhook.scope.partition'])->toBe('org_a')
        ->and($spans['webhooks.publish']['webhook.scope.key'])->toBe('engage')
        ->and($spans['webhooks.deliver']['webhook.scope.partition'])->toBe('org_a')
        ->and($spans['webhooks.deliver']['webhook.scope.key'])->toBe('engage')
        ->and($tags)->not->toBeEmpty()
        ->and($tags)->not->toContain('org_a')
        ->and($tags)->not->toContain('engage')
        ->and(array_filter($tags, static fn ($tag): bool => is_string($tag) && str_contains($tag, 'scope')))->toBe([]);

    // No partition: only the key is set.
    $this->registerWebhookEndpoint(Scope::of('solo'), 'https://receiver.test/solo', ['user.created']);
    app(Publisher::class)->publish(new OutboundEvent('user.created', Scope::of('solo'), ['id' => 3]));

    expect($spans['webhooks.publish'])->not->toHaveKey('webhook.scope.partition')
        ->and($spans['webhooks.publish']['webhook.scope.key'])->toBe('solo');
});
