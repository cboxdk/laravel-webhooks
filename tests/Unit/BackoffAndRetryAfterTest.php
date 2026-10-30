<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Cbox\Webhooks\Delivery\Backoff;
use Cbox\Webhooks\Delivery\RetryAfter;
use Cbox\Webhooks\Support\Config;
use Illuminate\Config\Repository;

function backoff(array $overrides = []): Backoff
{
    return new Backoff(new Config(new Repository(['webhooks' => array_replace_recursive(['retry' => ['schedule' => [10, 30, 120], 'jitter' => 0]], $overrides)])));
}

it('walks the schedule and exhausts past its end', function (): void {
    $backoff = backoff();

    expect($backoff->delayAfter(1))->toBe(10)
        ->and($backoff->delayAfter(2))->toBe(30)
        ->and($backoff->delayAfter(3))->toBe(120)
        ->and($backoff->delayAfter(4))->toBeNull()
        ->and($backoff->maxDelay())->toBe(120)
        ->and($backoff->maxAttempts())->toBe(4);
});

it('defaults to twelve attempts over about two days', function (): void {
    $backoff = new Backoff(new Config(new Repository(['webhooks' => []])));

    expect($backoff->maxAttempts())->toBe(12)->and($backoff->maxDelay())->toBe(86_400);
});

it('jitters inside the band and never below zero', function (): void {
    $backoff = backoff(['retry' => ['jitter' => 1.0]]);

    foreach (range(1, 50) as $_) {
        $delay = $backoff->delayAfter(1);
        expect($delay)->toBeGreaterThanOrEqual(0)->toBeLessThanOrEqual(20);
    }
});

it('parses Retry-After as seconds or an HTTP date', function (): void {
    $now = CarbonImmutable::parse('2026-10-30 12:00:00', 'UTC');

    expect(RetryAfter::seconds('120', $now))->toBe(120)
        ->and(RetryAfter::seconds(' 5 ', $now))->toBe(5)
        ->and(RetryAfter::seconds('Fri, 30 Oct 2026 12:10:00 GMT', $now))->toBe(600)
        ->and(RetryAfter::seconds('Fri, 30 Oct 2026 11:00:00 GMT', $now))->toBe(0)
        ->and(RetryAfter::seconds(null, $now))->toBeNull()
        ->and(RetryAfter::seconds('', $now))->toBeNull()
        ->and(RetryAfter::seconds('soon', $now))->toBeNull();
});
