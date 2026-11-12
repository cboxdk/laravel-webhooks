<?php

declare(strict_types=1);

namespace Cbox\Webhooks\Http\Requests;

use Cbox\Webhooks\Contracts\EventCatalog;
use Cbox\Webhooks\Contracts\EventCatalogs;
use Cbox\Webhooks\Contracts\ScopeResolver;
use Cbox\Webhooks\Exceptions\ScopeResolverMissing;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;

/**
 * Validation errors as the documented `422 {error: 'validation', errors}` shape
 * rather than the framework's default, so every error from the API has one form.
 */
abstract class WebhooksFormRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function failedValidation(Validator $validator): never
    {
        throw new HttpResponseException(new JsonResponse([
            'error' => 'validation',
            'errors' => $validator->errors()->toArray(),
        ], 422));
    }

    /**
     * The catalog subscriptions are validated against: the caller's scope's,
     * from the same resolver the controller uses.
     */
    protected function catalog(): EventCatalog
    {
        if (! $this->container->bound(ScopeResolver::class)) {
            throw ScopeResolverMissing::make();
        }

        return $this->container->make(EventCatalogs::class)->for(
            $this->container->make(ScopeResolver::class)->resolve($this),
        );
    }

    /**
     * @return list<string>
     */
    protected function stringList(string $key): array
    {
        $value = $this->input($key);

        return is_array($value) ? array_values(array_filter($value, 'is_string')) : [];
    }

    /**
     * @return array<string, string>
     */
    protected function stringMap(string $key): array
    {
        $value = $this->input($key);
        $out = [];

        if (! is_array($value)) {
            return [];
        }

        foreach ($value as $name => $item) {
            if (is_string($name) && is_string($item)) {
                $out[$name] = $item;
            }
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    protected function map(string $key): array
    {
        $value = $this->input($key);
        $out = [];

        if (! is_array($value)) {
            return [];
        }

        foreach ($value as $name => $item) {
            if (is_string($name)) {
                $out[$name] = $item;
            }
        }

        return $out;
    }

    protected function optionalString(string $key): ?string
    {
        $value = $this->input($key);

        return is_string($value) && $value !== '' ? $value : null;
    }

    protected function optionalInt(string $key): ?int
    {
        $value = $this->input($key);

        return is_numeric($value) ? (int) $value : null;
    }
}
