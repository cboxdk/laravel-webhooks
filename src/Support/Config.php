<?php

declare(strict_types=1);

namespace Cbox\Webhooks\Support;

use Illuminate\Contracts\Config\Repository;

/**
 * Typed reads of `config('webhooks.*')`, with the default applied whenever the
 * stored value is not of the expected shape. Centralised so no class in the
 * package carries its own `is_numeric` dance, and so the fallbacks are in one
 * auditable place.
 */
final readonly class Config
{
    public function __construct(private Repository $config) {}

    public function int(string $key, int $default): int
    {
        $value = $this->config->get('webhooks.'.$key, $default);

        return is_int($value) ? $value : (is_numeric($value) ? (int) $value : $default);
    }

    public function positiveInt(string $key, int $default): int
    {
        return max(1, $this->int($key, $default));
    }

    public function float(string $key, float $default): float
    {
        $value = $this->config->get('webhooks.'.$key, $default);

        return is_numeric($value) ? (float) $value : $default;
    }

    public function bool(string $key, bool $default): bool
    {
        $value = $this->config->get('webhooks.'.$key, $default);

        return is_bool($value) ? $value : $default;
    }

    public function string(string $key, ?string $default = null): ?string
    {
        $value = $this->config->get('webhooks.'.$key, $default);

        return is_string($value) && $value !== '' ? $value : $default;
    }

    /**
     * @param  list<int>  $default
     * @return list<int>
     */
    public function intList(string $key, array $default): array
    {
        $value = $this->config->get('webhooks.'.$key, $default);

        if (! is_array($value)) {
            $value = $default;
        }

        $out = [];

        foreach ($value as $item) {
            if (is_int($item) && $item >= 0) {
                $out[] = $item;
            } elseif (is_numeric($item) && (int) $item >= 0) {
                $out[] = (int) $item;
            }
        }

        return $out;
    }

    public function tablePrefix(): string
    {
        return $this->string('table_prefix', 'webhook_') ?? 'webhook_';
    }
}
