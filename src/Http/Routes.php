<?php

declare(strict_types=1);

namespace Cbox\Webhooks\Http;

use Cbox\Webhooks\Contracts\ScopeResolver;
use Cbox\Webhooks\Http\Controllers\CatalogController;
use Cbox\Webhooks\Http\Controllers\DeliveriesController;
use Cbox\Webhooks\Http\Controllers\EndpointActionsController;
use Cbox\Webhooks\Http\Controllers\EndpointsController;
use Illuminate\Routing\Router;

/**
 * Mounts the management API under the host's prefix and middleware. The host
 * decides who may call it; the package decides what each call may see, through
 * the {@see ScopeResolver} the host binds.
 *
 *     Routes::register($router, ['prefix' => 'api/webhooks', 'middleware' => ['api', 'auth:sanctum']]);
 *
 * @param  array{prefix?: string, middleware?: list<string>|string, as?: string}  $options
 */
final class Routes
{
    /**
     * @param  array{prefix?: string, middleware?: list<string>|string, as?: string}  $options
     */
    public static function register(Router $router, array $options = []): void
    {
        $group = [
            'prefix' => $options['prefix'] ?? 'webhooks',
            'as' => $options['as'] ?? 'webhooks.',
        ];

        if (isset($options['middleware'])) {
            $group['middleware'] = $options['middleware'];
        }

        $router->group($group, static function (Router $router): void {
            $router->get('catalog', [CatalogController::class, 'index'])->name('catalog');

            $router->get('endpoints', [EndpointsController::class, 'index'])->name('endpoints.index');
            $router->post('endpoints', [EndpointsController::class, 'store'])->name('endpoints.store');
            $router->get('endpoints/{id}', [EndpointsController::class, 'show'])->name('endpoints.show');
            $router->patch('endpoints/{id}', [EndpointsController::class, 'update'])->name('endpoints.update');
            $router->delete('endpoints/{id}', [EndpointsController::class, 'destroy'])->name('endpoints.destroy');

            $router->post('endpoints/{id}/pause', [EndpointActionsController::class, 'pause'])->name('endpoints.pause');
            $router->post('endpoints/{id}/resume', [EndpointActionsController::class, 'resume'])->name('endpoints.resume');
            $router->post('endpoints/{id}/rotate-secret', [EndpointActionsController::class, 'rotateSecret'])->name('endpoints.rotate-secret');
            $router->post('endpoints/{id}/ping', [EndpointActionsController::class, 'ping'])->name('endpoints.ping');
            $router->post('endpoints/{id}/replay', [EndpointActionsController::class, 'replay'])->name('endpoints.replay');
            $router->get('endpoints/{id}/deliveries', [EndpointActionsController::class, 'deliveries'])->name('endpoints.deliveries');
            $router->get('endpoints/{id}/stats', [EndpointActionsController::class, 'stats'])->name('endpoints.stats');

            $router->get('deliveries/{id}', [DeliveriesController::class, 'show'])->name('deliveries.show');
            $router->post('deliveries/{id}/retry', [DeliveriesController::class, 'retry'])->name('deliveries.retry');
        });
    }
}
