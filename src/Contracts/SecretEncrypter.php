<?php

declare(strict_types=1);

namespace Cbox\Webhooks\Contracts;

/**
 * How an endpoint's signing secret is kept at rest.
 *
 * The default uses the application's encrypter (the key in `APP_KEY`), which
 * is what 0.1 did through the `encrypted` cast, so existing rows read as they
 * are. A host that wants the secrets under their own key (a key per
 * environment, a KMS, a key it can rotate apart from `APP_KEY`) binds its own.
 *
 * Both directions must be deterministic about failure: `decrypt()` throws when
 * the stored value cannot be read, it never returns a guess. An implementation
 * that changes key must still read what the previous key wrote, or the host
 * re-encrypts the rows first.
 */
interface SecretEncrypter
{
    public function encrypt(string $secret): string;

    public function decrypt(string $stored): string;
}
