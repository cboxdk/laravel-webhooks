<?php

declare(strict_types=1);

use Cbox\Webhooks\Catalog\CatalogMarkdown;
use Cbox\Webhooks\Catalog\EventDefinition;
use Cbox\Webhooks\Catalog\InMemoryEventCatalog;
use Cbox\Webhooks\Exceptions\UnknownEventType;

function catalog(): InMemoryEventCatalog
{
    $catalog = new InMemoryEventCatalog;
    $catalog->register(
        new EventDefinition('user.created', 'user', 'User created', 'A user.'),
        new EventDefinition('user.deleted', 'user', 'User deleted', 'Gone | away.'),
        new EventDefinition('user.renamed', 'user', 'User renamed', 'Legacy.', [], 'user.updated'),
        new EventDefinition('user.updated', 'user', 'User updated', 'Changed.'),
        new EventDefinition('debug.tick', 'debug', 'Tick', 'Hidden.', [], null, false),
        new EventDefinition('invoice.paid', 'invoice', 'Paid', 'Money.'),
    );

    return $catalog;
}

it('expands wildcards to offered types and refuses unknown subscriptions', function (): void {
    $catalog = catalog();

    expect($catalog->expand(['*']))->toBe(['user.created', 'user.deleted', 'user.updated', 'invoice.paid'])
        ->and($catalog->expand(['user.*']))->toBe(['user.created', 'user.deleted', 'user.updated'])
        ->and($catalog->expand(['user.renamed', 'debug.tick']))->toBe(['user.renamed', 'debug.tick'])
        ->and($catalog->expand(['user.*', 'user.created']))->toBe(['user.created', 'user.deleted', 'user.updated'])
        ->and(fn () => $catalog->expand(['nope.*']))->toThrow(UnknownEventType::class)
        ->and(fn () => $catalog->expand(['user.nope']))->toThrow(UnknownEventType::class)
        ->and(fn () => $catalog->get('x'))->toThrow(UnknownEventType::class)
        ->and($catalog->get('user.created')->label)->toBe('User created');
});

it('covers a type by exact name, group wildcard or star, and keeps legacy types out of wildcards', function (): void {
    $catalog = catalog();

    expect($catalog->covers(['user.*'], 'user.created'))->toBeTrue()
        ->and($catalog->covers(['*'], 'invoice.paid'))->toBeTrue()
        ->and($catalog->covers(['invoice.paid'], 'invoice.paid'))->toBeTrue()
        ->and($catalog->covers(['invoice.*'], 'user.created'))->toBeFalse()
        ->and($catalog->covers(['user.*'], 'user.renamed'))->toBeFalse()
        ->and($catalog->covers(['*'], 'debug.tick'))->toBeFalse()
        ->and($catalog->covers(['user.renamed'], 'user.renamed'))->toBeTrue()
        ->and($catalog->covers(['unknown.type'], 'unknown.type'))->toBeTrue();
});

it('renders markdown grouped by group with escaped pipes', function (): void {
    $markdown = (new CatalogMarkdown(catalog()))->render();

    expect($markdown)->toContain("### user\n\n| Event | Status | Description |")
        ->toContain('| `user.deleted` | current | Gone \| away. |')
        ->toContain('| `user.renamed` | legacy → `user.updated` | Legacy. |')
        ->toContain('| `debug.tick` | not offered | Hidden. |')
        ->toContain("\n### invoice\n");
});

it('serialises a definition for the API', function (): void {
    $array = (new EventDefinition('user.created', 'user', 'User created', 'A user.', ['id' => 'usr_1']))->toArray();

    expect($array)->toBe([
        'type' => 'user.created', 'group' => 'user', 'label' => 'User created', 'description' => 'A user.',
        'example' => ['id' => 'usr_1'], 'superseded_by' => null, 'offered' => true,
    ]);
});
