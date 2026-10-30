<?php

declare(strict_types=1);

arch('no debug calls left behind')
    ->expect(['dd', 'dump', 'ray', 'var_dump', 'print_r'])
    ->not->toBeUsed();

arch('strict types everywhere')
    ->expect('Cbox\Webhooks')
    ->toUseStrictTypes();

arch('contracts are interfaces')
    ->expect('Cbox\Webhooks\Contracts')
    ->toBeInterfaces();

arch('exceptions extend the package base exception')
    ->expect('Cbox\Webhooks\Exceptions')
    ->classes()
    ->toExtend('Cbox\Webhooks\Exceptions\WebhooksException')
    ->ignoring('Cbox\Webhooks\Exceptions\WebhooksException');

arch('the delivery engine never reaches for a facade')
    ->expect(['Cbox\Webhooks\Delivery', 'Cbox\Webhooks\Publishing', 'Cbox\Webhooks\Housekeeping', 'Cbox\Webhooks\Receiving', 'Cbox\Webhooks\Endpoints', 'Cbox\Webhooks\Monitoring'])
    ->not->toUse('Illuminate\Support\Facades');

arch('no env() reads and no process exits')
    ->expect(['env', 'exit', 'die', 'sleep', 'usleep'])
    ->not->toBeUsed();

arch('final by default')
    ->expect('Cbox\Webhooks')
    ->classes()
    ->toBeFinal()
    ->ignoring([
        // Configurable through webhooks.models.*: a host subclasses to add a global scope.
        'Cbox\Webhooks\Models\WebhookEndpoint',
        'Cbox\Webhooks\Models\WebhookEvent',
        'Cbox\Webhooks\Models\WebhookDelivery',
        'Cbox\Webhooks\Models\WebhookAttempt',
        'Cbox\Webhooks\Models\WebhookInboundDelivery',
        // A host may extend the provider to add bindings.
        'Cbox\Webhooks\WebhooksServiceProvider',
        // Abstract bases.
        'Cbox\Webhooks\Exceptions\WebhooksException',
        'Cbox\Webhooks\Http\Requests\WebhooksFormRequest',
        // Value objects a contract returns: a consumer's test double builds or
        // stubs them, so they stay open (readonly already blocks Mockery, which
        // is why each has a meaningful zero-argument form).
        'Cbox\Webhooks\ValueObjects\Endpoint',
        'Cbox\Webhooks\ValueObjects\EndpointHealth',
        'Cbox\Webhooks\ValueObjects\EndpointStats',
        'Cbox\Webhooks\ValueObjects\RegisteredEndpoint',
        'Cbox\Webhooks\ValueObjects\RotatedSecret',
        'Cbox\Webhooks\ValueObjects\PublishedEvent',
        'Cbox\Webhooks\ValueObjects\Delivery',
        'Cbox\Webhooks\ValueObjects\Attempt',
        'Cbox\Webhooks\ValueObjects\AttemptResult',
        'Cbox\Webhooks\ValueObjects\Overview',
        'Cbox\Webhooks\ValueObjects\IncomingWebhook',
    ]);

arch('value objects are readonly')
    ->expect('Cbox\Webhooks\ValueObjects')
    ->classes()
    ->toBeReadonly();

arch('events are readonly')
    ->expect('Cbox\Webhooks\Events')
    ->classes()
    ->toBeReadonly();
