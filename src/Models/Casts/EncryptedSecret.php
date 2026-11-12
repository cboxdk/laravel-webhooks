<?php

declare(strict_types=1);

namespace Cbox\Webhooks\Models\Casts;

use Cbox\Webhooks\Contracts\SecretEncrypter;
use Illuminate\Container\Container;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * A secret column through the bound {@see SecretEncrypter}, resolved at use so
 * a host's binding (or a test's) is what encrypts and decrypts. Null stays
 * null: the previous secret is absent outside a rotation overlap.
 *
 * @implements CastsAttributes<string|null, string|null>
 */
final class EncryptedSecret implements CastsAttributes
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        return is_string($value) ? $this->encrypter()->decrypt($value) : null;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        return is_string($value) ? $this->encrypter()->encrypt($value) : null;
    }

    private function encrypter(): SecretEncrypter
    {
        return Container::getInstance()->make(SecretEncrypter::class);
    }
}
