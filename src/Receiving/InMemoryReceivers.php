<?php

declare(strict_types=1);

namespace Cbox\Webhooks\Receiving;

use Cbox\Webhooks\Contracts\Receivers;
use Cbox\Webhooks\ValueObjects\IncomingWebhook;
use Closure;

final class InMemoryReceivers implements Receivers
{
    /** @var array<string, list<class-string|Closure(IncomingWebhook): void>> */
    private array $handlers = [];

    public function on(string $pattern, string|Closure $handler): void
    {
        $this->handlers[$pattern][] = $handler;
    }

    public function handlersFor(string $type): array
    {
        $out = [];

        foreach ($this->handlers as $pattern => $handlers) {
            if ($this->matches($pattern, $type)) {
                foreach ($handlers as $handler) {
                    $out[] = $handler;
                }
            }
        }

        return $out;
    }

    public function hasHandlersFor(string $type): bool
    {
        return $this->handlersFor($type) !== [];
    }

    private function matches(string $pattern, string $type): bool
    {
        if ($pattern === '*' || $pattern === $type) {
            return true;
        }

        if (str_ends_with($pattern, '.*')) {
            return str_starts_with($type, substr($pattern, 0, -1));
        }

        return false;
    }
}
