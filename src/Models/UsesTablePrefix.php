<?php

declare(strict_types=1);

namespace Cbox\Webhooks\Models;

/**
 * Resolves the table name from `webhooks.table_prefix` and the connection from
 * `webhooks.connection` at use, so the same model class serves a host that
 * renamed the tables or keeps them in a database of their own.
 */
trait UsesTablePrefix
{
    public function getTable(): string
    {
        $prefix = config('webhooks.table_prefix', 'webhook_');

        return (is_string($prefix) ? $prefix : 'webhook_').static::TABLE;
    }

    /**
     * A connection set on the instance (or by a subclass) wins; otherwise the
     * configured one; otherwise the application's default.
     */
    public function getConnectionName(): ?string
    {
        $own = parent::getConnectionName();

        if (is_string($own) && $own !== '') {
            return $own;
        }

        $configured = config('webhooks.connection');

        return is_string($configured) && $configured !== '' ? $configured : null;
    }
}
