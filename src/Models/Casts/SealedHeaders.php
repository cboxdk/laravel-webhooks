<?php

declare(strict_types=1);

namespace Cbox\Webhooks\Models\Casts;

use Cbox\Webhooks\Contracts\SecretEncrypter;
use Cbox\Webhooks\Support\Json;
use Illuminate\Container\Container;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * An endpoint's own headers, names in the clear and values sealed with the
 * bound {@see SecretEncrypter}: a header is where an owner puts a bearer token
 * or an API key, so it is kept at rest like the signing secret.
 *
 * The column stays a JSON object of name to value. A sealed value carries the
 * `sealed:` mark, so a value written in plain text by 0.2 (before the sealing
 * migration ran, or by a worker that had not been redeployed yet) is still
 * read as it is, and sealed the next time the row is saved. Null and an empty
 * map are both stored as null.
 *
 * @implements CastsAttributes<array<string, string>, array<string, string>|null>
 */
final class SealedHeaders implements CastsAttributes
{
    public const string MARK = 'sealed:';

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, string>
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): array
    {
        if (! is_string($value) || $value === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return self::unseal(Json::stringMap($decoded), $this->encrypter());
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        $headers = Json::stringMap($value);

        if ($headers === []) {
            return null;
        }

        return Json::encode(self::seal($headers, $this->encrypter()));
    }

    /**
     * Seal every value that is not sealed yet.
     *
     * @param  array<string, string>  $headers
     * @return array<string, string>
     */
    public static function seal(array $headers, SecretEncrypter $encrypter): array
    {
        $out = [];

        foreach ($headers as $name => $value) {
            $out[$name] = str_starts_with($value, self::MARK) ? $value : self::MARK.$encrypter->encrypt($value);
        }

        return $out;
    }

    /**
     * Open every sealed value; a value without the mark is plain text from 0.2.
     *
     * @param  array<string, string>  $headers
     * @return array<string, string>
     */
    public static function unseal(array $headers, SecretEncrypter $encrypter): array
    {
        $out = [];

        foreach ($headers as $name => $value) {
            $out[$name] = str_starts_with($value, self::MARK) ? $encrypter->decrypt(substr($value, strlen(self::MARK))) : $value;
        }

        return $out;
    }

    private function encrypter(): SecretEncrypter
    {
        return Container::getInstance()->make(SecretEncrypter::class);
    }
}
