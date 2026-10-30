<?php

declare(strict_types=1);

namespace Cbox\Webhooks\Contracts;

use Cbox\Webhooks\ValueObjects\AttemptResult;

/**
 * The worker side: one HTTP attempt for one delivery or one batch.
 *
 * `deliver()` first claims the attempt with a conditional update that succeeds
 * only while the row still holds `$expectedAttempt` and is claimable, so two
 * jobs for the same attempt send once. Null means the claim lost.
 */
interface Deliverer
{
    public function deliver(string $deliveryId, int $expectedAttempt): ?AttemptResult;

    /** One request for every in-flight delivery in the batch; null when the batch is empty. */
    public function deliverBatch(string $batchId): ?AttemptResult;
}
