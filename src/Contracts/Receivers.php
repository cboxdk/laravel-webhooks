<?php

declare(strict_types=1);

namespace Cbox\Webhooks\Contracts;

use Cbox\Webhooks\ValueObjects\IncomingWebhook;
use Closure;

/**
 * The receiver side's handler registry. A handler is a class name (resolved
 * from the container, invoked through `__invoke(IncomingWebhook)` or
 * `handle(IncomingWebhook)`) or a closure. Patterns follow the catalog's:
 * `*`, `group.*`, or an exact type.
 */
interface Receivers
{
    /**
     * @param  class-string|Closure(IncomingWebhook): void  $handler
     */
    public function on(string $pattern, string|Closure $handler): void;

    /**
     * @return list<class-string|Closure(IncomingWebhook): void>
     */
    public function handlersFor(string $type): array;

    public function hasHandlersFor(string $type): bool;
}
