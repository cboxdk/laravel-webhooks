---
title: Secret encryption key
description: Bind SecretEncrypter to keep endpoint signing secrets under a key of your choice instead of APP_KEY.
weight: 60
---

# Secret encryption key

An endpoint's signing secret (and the previous one, during a rotation overlap) is
encrypted before it is stored, and so are the values of the endpoint's own headers
(stored with a `sealed:` mark in front). By default that is Laravel's encrypter with `APP_KEY`.

To keep the secrets under another key (one per environment, one you can rotate apart
from `APP_KEY`, one held by a KMS), bind `Cbox\Webhooks\Contracts\SecretEncrypter`:

```php
use Cbox\Webhooks\Contracts\SecretEncrypter;
use Illuminate\Encryption\Encrypter;

final class WebhookSecretEncrypter implements SecretEncrypter
{
    private Encrypter $current;

    public function __construct()
    {
        $this->current = new Encrypter(base64_decode(config('services.webhooks.secret_key')), 'aes-256-gcm');
    }

    public function encrypt(string $secret): string
    {
        return $this->current->encryptString($secret);
    }

    public function decrypt(string $stored): string
    {
        return $this->current->decryptString($stored);
    }
}

$this->app->singleton(SecretEncrypter::class, WebhookSecretEncrypter::class);
```

The example uses Laravel's own `Encrypter` class with a different key. Use a vetted
primitive: do not write your own cipher.

## Rules

- `decrypt()` throws when it cannot read a value. It never returns a guess: a wrong
  secret would sign requests no receiver accepts.
- **Changing the encrypter does not re-encrypt what is stored.** Rows written under the
  previous key must stay readable: either make `decrypt()` try the old key as well
  (Laravel's `Encrypter::previousKeys()` does this), or re-save every endpoint's
  `secret` and `previous_secret` in a one-off command before you remove the old key.
- The same applies when moving off the default: rows written by 0.1 are under
  `APP_KEY`.
- The secret is decrypted in the worker for every attempt, to sign. A remote KMS call
  per attempt is a latency and availability cost; cache a data key in memory instead.

This protects the stored secrets from someone who has the database but not the key. It
does not protect them from someone who has both.
