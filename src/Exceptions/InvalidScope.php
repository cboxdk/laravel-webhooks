<?php

declare(strict_types=1);

namespace Cbox\Webhooks\Exceptions;

final class InvalidScope extends WebhooksException
{
    public static function emptyKey(): self
    {
        return new self('A scope key must not be empty. Use Scope::partitionWide() or Scope::global() for a wide scope, on purpose.');
    }

    public static function emptyPartition(): self
    {
        return new self('A scope partition must not be empty when given.');
    }

    public static function wideScopeNotAllowedHere(string $operation): self
    {
        return new self(sprintf('%s needs an owner scope (Scope::of()). Wide endpoints are registered through registerPartitionWide() and registerGlobal().', $operation));
    }
}
