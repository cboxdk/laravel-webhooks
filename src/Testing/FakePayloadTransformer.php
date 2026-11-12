<?php

declare(strict_types=1);

namespace Cbox\Webhooks\Testing;

use Cbox\Webhooks\Contracts\PayloadTransformer;
use Cbox\Webhooks\Exceptions\InvalidEndpoint;
use Cbox\Webhooks\Exceptions\TransformFailed;
use Closure;

/**
 * A transformer for tests: each expression is a name you define with a
 * closure. An expression nobody defined is the identity on `data`, so an
 * endpoint can be given a transform without ceremony; one you `refuse()` fails
 * validation. Every input it was given is kept for assertions.
 */
final class FakePayloadTransformer implements PayloadTransformer
{
    /** @var array<string, Closure(array{id: string, type: string, occurred_at: string, subject: string|null, data: array<string, mixed>}): mixed> */
    private array $definitions = [];

    /** @var array<string, true> */
    private array $refused = [];

    /** @var list<array{id: string, type: string, occurred_at: string, subject: string|null, data: array<string, mixed>}> */
    private array $inputs = [];

    /**
     * @param  Closure(array{id: string, type: string, occurred_at: string, subject: string|null, data: array<string, mixed>}): mixed  $apply
     */
    public function define(string $expression, Closure $apply): self
    {
        $this->definitions[$expression] = $apply;

        return $this;
    }

    public function refuse(string $expression): self
    {
        $this->refused[$expression] = true;

        return $this;
    }

    public function validate(string $expression): void
    {
        if (isset($this->refused[$expression])) {
            throw InvalidEndpoint::invalidTransform(sprintf('[%s] does not compile.', $expression));
        }
    }

    public function apply(string $expression, array $input): mixed
    {
        $this->inputs[] = $input;

        if (isset($this->refused[$expression])) {
            throw new TransformFailed(sprintf('[%s] does not compile.', $expression));
        }

        $definition = $this->definitions[$expression] ?? null;

        return $definition === null ? $input['data'] : $definition($input);
    }

    /**
     * @return list<array{id: string, type: string, occurred_at: string, subject: string|null, data: array<string, mixed>}>
     */
    public function inputs(): array
    {
        return $this->inputs;
    }
}
