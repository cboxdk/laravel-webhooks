<?php

declare(strict_types=1);

namespace Cbox\Webhooks\Tests;

use Cbox\Ssrf\SsrfServiceProvider;
use Cbox\Webhooks\Catalog\EventDefinition;
use Cbox\Webhooks\Contracts\EventCatalog;
use Cbox\Webhooks\Testing\InteractsWithWebhooks;
use Cbox\Webhooks\Tests\Fixtures\CarbonClock;
use Cbox\Webhooks\Tests\Fixtures\LatestStubFirstHttpFactory;
use Cbox\Webhooks\WebhooksServiceProvider;
use Cbox\WebhookSignature\Contracts\Clock;
use Cbox\WebhookSignature\WebhookSignatureServiceProvider;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    use InteractsWithWebhooks;
    use RefreshDatabase;

    /**
     * @param  Application  $app
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [SsrfServiceProvider::class, WebhookSignatureServiceProvider::class, WebhooksServiceProvider::class];
    }

    /**
     * @param  Application  $app
     */
    protected function defineEnvironment($app): void
    {
        $config = $app['config'];

        $config->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
        $config->set('database.default', 'testing');
        $config->set('database.connections.testing', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true]);
        $config->set('cache.default', 'array');
        $config->set('queue.default', 'sync');
        $config->set('webhooks.telemetry.enabled', false);
        $config->set('webhooks.retry.jitter', 0);
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Later stubs win (see the fixture), so a test can override this default 200 per URL.
        Http::swap(new LatestStubFirstHttpFactory(app(Dispatcher::class)));
        Http::preventStrayRequests();
        app()->instance(Clock::class, new CarbonClock);
        Http::fake();

        $this->fakeSsrfDns(['receiver.test' => ['93.184.216.34'], 'other.test' => ['93.184.216.35']]);

        app(EventCatalog::class)->register(
            new EventDefinition('user.created', 'user', 'User created', 'A user was created.', ['id' => 'usr_1']),
            new EventDefinition('user.deleted', 'user', 'User deleted', 'A user was deleted.'),
            new EventDefinition('invoice.paid', 'invoice', 'Invoice paid', 'An invoice was paid.'),
            new EventDefinition('user.renamed', 'user', 'User renamed', 'Legacy.', [], 'user.updated'),
            new EventDefinition('user.updated', 'user', 'User updated', 'A user changed.'),
            new EventDefinition('webhook.ping', 'webhook', 'Ping', 'A test delivery.'),
        );
    }
}
