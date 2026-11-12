<?php

declare(strict_types=1);

use Cbox\Webhooks\ValueObjects\Attempt;
use Cbox\Webhooks\ValueObjects\AttemptResult;
use Cbox\Webhooks\ValueObjects\Delivery;
use Cbox\Webhooks\ValueObjects\Endpoint;
use Cbox\Webhooks\ValueObjects\EndpointChanges;
use Cbox\Webhooks\ValueObjects\EndpointHealth;
use Cbox\Webhooks\ValueObjects\EndpointStats;
use Cbox\Webhooks\ValueObjects\IncomingWebhook;
use Cbox\Webhooks\ValueObjects\Overview;
use Cbox\Webhooks\ValueObjects\Page;
use Cbox\Webhooks\ValueObjects\PublishedEvent;
use Cbox\Webhooks\ValueObjects\RecordedEvent;
use Cbox\Webhooks\ValueObjects\RegisteredEndpoint;
use Cbox\Webhooks\ValueObjects\RotatedSecret;

it('builds every contract-returned value object with no arguments, so a test double can', function (): void {
    expect(new Endpoint)->toBeInstanceOf(Endpoint::class)
        ->and((new Endpoint)->toArray()['status'])->toBe('active')
        ->and((new EndpointHealth)->isTripped())->toBeFalse()
        ->and((new EndpointStats)->toArray()['delivered'])->toBe(0)
        ->and((new RegisteredEndpoint)->toArray())->toHaveKey('secret')
        ->and((new RotatedSecret)->toArray()['previous_secret_expires_at'])->toBeNull()
        ->and((new PublishedEvent)->duplicate)->toBeFalse()
        ->and((new Delivery)->toArray())->not->toHaveKey('attempts')
        ->and((new Delivery(attempts: [new Attempt]))->toArray()['attempts'])->toHaveCount(1)
        ->and((new AttemptResult)->succeeded())->toBeTrue()
        ->and((new AttemptResult)->toArray()['outcome'])->toBe('success')
        ->and((new Overview)->toArray()['endpoints']['active'])->toBe(0)
        ->and((new IncomingWebhook)->type)->toBe('')
        ->and((new Page)->hasMore())->toBeFalse()
        ->and((new Page)->isEmpty())->toBeTrue()
        ->and((new RecordedEvent)->toArray()['payload'])->toBe([])
        ->and((new Attempt)->scope->isGlobal())->toBeTrue()
        ->and((new EndpointChanges)->isEmpty())->toBeTrue()
        ->and((new EndpointChanges(url: 'x'))->isEmpty())->toBeFalse();
});
