<?php

declare(strict_types=1);

namespace Cbox\Webhooks\ValueObjects;

use Cbox\Webhooks\Exceptions\InvalidScope;

/**
 * Who an endpoint belongs to, and who an event is about, without this package
 * owning the host's tenancy.
 *
 * `partition` is the host's hard tenant boundary (an environment, a plane); `key`
 * is the owner inside it (an organization, a source). An endpoint whose key is
 * null receives every owner's events inside its partition; one with both null
 * receives everything. Those two shapes are reachable only through the named
 * constructors, so a null that arrived from a variable cannot widen a scope by
 * accident: `Scope::of()` refuses an empty key outright.
 */
final readonly class Scope
{
    /**
     * Public so a value object may default to `new Scope(null, null)`, but
     * application code reaches a scope through the named constructors: they are
     * the ones that refuse an empty key and make a wide scope a visible choice.
     */
    public function __construct(
        public ?string $partition,
        public ?string $key,
    ) {}

    /** An owner: an organization, a project, a source. */
    public static function of(string $key, ?string $partition = null): self
    {
        if (trim($key) === '') {
            throw InvalidScope::emptyKey();
        }

        if ($partition !== null && trim($partition) === '') {
            throw InvalidScope::emptyPartition();
        }

        return new self($partition, $key);
    }

    /** Every owner inside one partition. Operator plane only. */
    public static function partitionWide(string $partition): self
    {
        if (trim($partition) === '') {
            throw InvalidScope::emptyPartition();
        }

        return new self($partition, null);
    }

    /** Every owner everywhere. Operator plane only. */
    public static function global(): self
    {
        return new self(null, null);
    }

    /**
     * Rebuild from stored columns. Only the models call this; it accepts the wide
     * shapes because a row that holds them was written through the guarded path.
     */
    public static function fromColumns(?string $partition, ?string $key): self
    {
        return new self($partition, $key);
    }

    /**
     * Whether an endpoint in this scope receives an event in `$event`'s scope:
     * each of partition and key is either unconstrained here or equal.
     */
    public function matches(self $event): bool
    {
        return ($this->partition === null || $this->partition === $event->partition)
            && ($this->key === null || $this->key === $event->key);
    }

    public function equals(self $other): bool
    {
        return $this->partition === $other->partition && $this->key === $other->key;
    }

    /** True for the partition-wide and global shapes. */
    public function isWide(): bool
    {
        return $this->key === null;
    }

    public function isGlobal(): bool
    {
        return $this->partition === null && $this->key === null;
    }

    /**
     * @return array{partition: string|null, key: string|null}
     */
    public function toArray(): array
    {
        return ['partition' => $this->partition, 'key' => $this->key];
    }

    public function toString(): string
    {
        return ($this->partition ?? '*').'/'.($this->key ?? '*');
    }
}
