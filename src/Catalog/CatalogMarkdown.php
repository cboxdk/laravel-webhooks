<?php

declare(strict_types=1);

namespace Cbox\Webhooks\Catalog;

use Cbox\Webhooks\Contracts\EventCatalog;

/**
 * Renders the catalog as Markdown tables, one per group, so a host's documented
 * event list is generated from the registrations rather than kept beside them.
 * A host's test can assert its committed page equals `render()`.
 */
final readonly class CatalogMarkdown
{
    public function __construct(private EventCatalog $catalog) {}

    public function render(): string
    {
        $groups = [];

        foreach ($this->catalog->all() as $definition) {
            $groups[$definition->group][] = $definition;
        }

        $out = [];

        foreach ($groups as $group => $definitions) {
            if ($out !== []) {
                $out[] = '';
            }

            $out[] = '### '.$group;
            $out[] = '';
            $out[] = '| Event | Status | Description |';
            $out[] = '|---|---|---|';

            foreach ($definitions as $definition) {
                $out[] = sprintf(
                    '| `%s` | %s | %s |',
                    $definition->type,
                    $this->status($definition),
                    str_replace('|', '\\|', $definition->description),
                );
            }
        }

        return implode("\n", $out)."\n";
    }

    private function status(EventDefinition $definition): string
    {
        if ($definition->supersededBy !== null) {
            return 'legacy → `'.$definition->supersededBy.'`';
        }

        return $definition->offered ? 'current' : 'not offered';
    }
}
