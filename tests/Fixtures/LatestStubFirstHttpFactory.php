<?php

declare(strict_types=1);

namespace Cbox\Webhooks\Tests\Fixtures;

use Illuminate\Http\Client\Factory;
use Illuminate\Support\Collection;

/**
 * Laravel's HTTP fake answers with the first stub that matches, so a catch-all
 * registered in the base test case would shadow every stub a test adds later.
 * This factory puts the stubs of each later `fake()` call in front of the
 * earlier ones, so the base case can default the receiver to 200 and a test can
 * still say `Http::fake(['receiver.test/*' => Http::response('', 500)])`.
 */
final class LatestStubFirstHttpFactory extends Factory
{
    private int $depth = 0;

    public function fake($callback = null)
    {
        if ($this->depth > 0) {
            return parent::fake($callback);
        }

        $this->depth++;

        try {
            $earlier = $this->stubCallbacks;
            $this->stubCallbacks = new Collection;
            parent::fake($callback);
            $this->stubCallbacks = $this->stubCallbacks->merge($earlier);
        } finally {
            $this->depth--;
        }

        return $this;
    }
}
