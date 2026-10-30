<?php

declare(strict_types=1);

namespace Cbox\Webhooks\Endpoints;

/**
 * A fresh secret in the form the scheme's receivers expect: 64 hex characters
 * for the Cbox scheme (the HMAC key as written), and the `whsec_` base64 form
 * for Standard Webhooks so an owner can paste it into any library for that
 * specification without converting. Any other scheme gets the hex form.
 */
final class SecretMinter
{
    public static function for(string $scheme): string
    {
        if ($scheme === 'standard-webhooks') {
            return 'whsec_'.base64_encode(random_bytes(24));
        }

        return bin2hex(random_bytes(32));
    }
}
