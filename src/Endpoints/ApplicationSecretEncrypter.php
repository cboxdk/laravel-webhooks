<?php

declare(strict_types=1);

namespace Cbox\Webhooks\Endpoints;

use Cbox\Webhooks\Contracts\SecretEncrypter;
use Illuminate\Contracts\Encryption\StringEncrypter;

/**
 * The default: Laravel's own encrypter and key, string mode, which is exactly
 * what the `encrypted` cast writes. Rows stored by 0.1 are read unchanged.
 */
final readonly class ApplicationSecretEncrypter implements SecretEncrypter
{
    public function __construct(private StringEncrypter $encrypter) {}

    public function encrypt(string $secret): string
    {
        return $this->encrypter->encryptString($secret);
    }

    public function decrypt(string $stored): string
    {
        return $this->encrypter->decryptString($stored);
    }
}
