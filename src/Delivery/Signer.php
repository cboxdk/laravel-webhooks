<?php

declare(strict_types=1);

namespace Cbox\Webhooks\Delivery;

use Cbox\Webhooks\Exceptions\InvalidEndpoint;
use Cbox\WebhookSignature\Contracts\SignsWebhooks;
use Cbox\WebhookSignature\Contracts\Webhooks as Signatures;
use Cbox\WebhookSignature\Exceptions\ConfigurationError;
use Cbox\WebhookSignature\Schemes\StandardWebhooksScheme;
use Cbox\WebhookSignature\ValueObjects\Secret;
use Cbox\WebhookSignature\ValueObjects\WebhookMessage;

/**
 * Signature headers for one attempt, through the signature package's schemes
 * so this side and a receiver using the same package agree byte for byte.
 *
 * Always the current secret: the rotation overlap is the receiver's concern
 * (its secret set accepts both), and signing with both would hand every
 * receiver two MACs for no one's benefit.
 *
 * The timestamp is fresh per attempt so a retry passes the receiver's freshness
 * window. With Standard Webhooks the delivery (or batch) id is the `webhook-id`,
 * the spec's dedupe key, and it is set on the message before signing because
 * the scheme binds it into the signed bytes.
 *
 * @return array<string, string>
 */
final readonly class Signer
{
    public function __construct(private Signatures $signatures) {}

    /**
     * @return array<string, string>
     */
    public function headers(string $scheme, string $secret, string $body, string $messageId, int $timestamp): array
    {
        $signer = $this->scheme($scheme);

        $message = WebhookMessage::make($body, [StandardWebhooksScheme::ID_HEADER => $messageId]);

        return $signer->sign($message, new Secret($secret), $timestamp)->all();
    }

    /** Whether a scheme name is registered and can sign. */
    public function canSign(string $scheme): bool
    {
        try {
            $this->scheme($scheme);

            return true;
        } catch (InvalidEndpoint) {
            return false;
        }
    }

    private function scheme(string $name): SignsWebhooks
    {
        try {
            $scheme = $this->signatures->scheme($name);
        } catch (ConfigurationError) {
            throw InvalidEndpoint::schemeCannotSign($name);
        }

        if (! $scheme instanceof SignsWebhooks) {
            throw InvalidEndpoint::schemeCannotSign($name);
        }

        return $scheme;
    }
}
