<?php

declare(strict_types=1);

namespace Cbox\Webhooks\Contracts;

use Cbox\Webhooks\ValueObjects\Scope;
use Illuminate\Http\Request;

/**
 * The host tells the management API whose endpoints a request may see. Bound
 * by the host; a tenant middleware that already knows the caller is the usual
 * implementation. Without a binding the routes refuse to serve.
 */
interface ScopeResolver
{
    public function resolve(Request $request): Scope;
}
