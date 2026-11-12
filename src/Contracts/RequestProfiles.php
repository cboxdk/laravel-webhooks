<?php

declare(strict_types=1);

namespace Cbox\Webhooks\Contracts;

use Cbox\Webhooks\ValueObjects\Endpoint;
use Cbox\Webhooks\ValueObjects\RequestProfile;

/**
 * The user agent and the fixed headers for requests to one endpoint.
 *
 * The default answers `webhooks.http.user_agent` and `webhooks.http.headers`
 * for every endpoint. A host that sends on behalf of several products or
 * customers binds its own and decides from the endpoint: its scope
 * (`$endpoint->scope`), or its own `metadata`.
 *
 * Called once per attempt, in the worker, so keep it cheap. A header returned
 * here can never replace a signature header, a transport header or one of the
 * `X-Webhook-*` set: those are written after it.
 */
interface RequestProfiles
{
    public function for(Endpoint $endpoint): RequestProfile;
}
