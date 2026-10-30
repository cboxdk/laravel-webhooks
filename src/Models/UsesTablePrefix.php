<?php

declare(strict_types=1);

namespace Cbox\Webhooks\Models;

/**
 * Resolves the table name from `webhooks.table_prefix` at construction, so the
 * same model class serves a host that renamed the tables.
 */
trait UsesTablePrefix
{
    public function getTable(): string
    {
        $prefix = config('webhooks.table_prefix', 'webhook_');

        return (is_string($prefix) ? $prefix : 'webhook_').static::TABLE;
    }
}
