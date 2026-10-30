<?php

declare(strict_types=1);

use Cbox\Webhooks\Exceptions\InvalidScope;
use Cbox\Webhooks\ValueObjects\Scope;

it('matches an owner scope only exactly, a partition-wide scope within its partition, a global scope everywhere', function (): void {
    $owner = Scope::of('org_1', 'env_1');
    $wide = Scope::partitionWide('env_1');
    $global = Scope::global();

    expect($owner->matches(Scope::of('org_1', 'env_1')))->toBeTrue()
        ->and($owner->matches(Scope::of('org_2', 'env_1')))->toBeFalse()
        ->and($owner->matches(Scope::of('org_1', 'env_2')))->toBeFalse()
        ->and($owner->matches(Scope::of('org_1')))->toBeFalse()
        ->and($wide->matches(Scope::of('org_9', 'env_1')))->toBeTrue()
        ->and($wide->matches(Scope::of('org_9', 'env_2')))->toBeFalse()
        ->and($global->matches(Scope::of('org_9', 'env_2')))->toBeTrue()
        ->and($global->matches(Scope::of('x')))->toBeTrue();
});

it('refuses empty keys and partitions', function (): void {
    expect(fn () => Scope::of(''))->toThrow(InvalidScope::class)
        ->and(fn () => Scope::of('  '))->toThrow(InvalidScope::class)
        ->and(fn () => Scope::of('k', ''))->toThrow(InvalidScope::class)
        ->and(fn () => Scope::partitionWide(''))->toThrow(InvalidScope::class);
});

it('describes itself', function (): void {
    expect(Scope::of('org_1', 'env_1')->toArray())->toBe(['partition' => 'env_1', 'key' => 'org_1'])
        ->and(Scope::of('org_1')->toString())->toBe('*/org_1')
        ->and(Scope::global()->isGlobal())->toBeTrue()
        ->and(Scope::partitionWide('p')->isWide())->toBeTrue()
        ->and(Scope::of('a')->isWide())->toBeFalse()
        ->and(Scope::of('a')->equals(Scope::of('a')))->toBeTrue()
        ->and(Scope::of('a')->equals(Scope::of('a', 'p')))->toBeFalse();
});
