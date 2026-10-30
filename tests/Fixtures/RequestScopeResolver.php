<?php

declare(strict_types=1);

namespace Cbox\Webhooks\Tests\Fixtures;

use Cbox\Webhooks\Contracts\ScopeResolver;
use Cbox\Webhooks\ValueObjects\Scope;
use Illuminate\Http\Request;

/** The test host's resolver: the caller's scope from two headers. */
final class RequestScopeResolver implements ScopeResolver
{
    public function resolve(Request $request): Scope
    {
        $partition = $request->headers->get('X-Partition');

        return Scope::of((string) $request->headers->get('X-Org', 'org_1'), $partition === '' ? null : $partition);
    }
}
