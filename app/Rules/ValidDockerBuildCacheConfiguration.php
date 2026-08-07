<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\Validator;
use Illuminate\Translation\PotentiallyTranslatedString;

final class ValidDockerBuildCacheConfiguration implements ValidationRule
{
    /**
     * Run the validation rule.
     *
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_array($value)) {
            $fail('The :attribute must be a Docker build cache configuration.');

            return;
        }

        if (($value['enabled'] ?? null) === false) {
            if (array_keys($value) !== ['enabled']) {
                $fail('A disabled :attribute configuration cannot define cache endpoints.');
            }

            return;
        }

        if (($value['enabled'] ?? null) !== true) {
            $fail('The :attribute enabled value must be a boolean.');

            return;
        }

        $validator = Validator::make($value, [
            'enabled' => ['required', 'boolean', 'accepted'],
            'cache_from' => ['required', 'array:type,value'],
            'cache_from.type' => ['required', 'in:registry,raw'],
            'cache_from.value' => ['required', 'string', 'max:2048'],
            'cache_to' => ['required', 'array:type,value'],
            'cache_to.type' => ['required', 'in:registry,raw'],
            'cache_to.value' => ['required', 'string', 'max:2048'],
            'failure_policy' => ['required', 'in:continue,fail'],
        ]);

        if ($validator->fails()) {
            $fail($validator->errors()->first());

            return;
        }

        foreach (['cache_from', 'cache_to'] as $endpoint) {
            /** @var array{type: string, value: string} $cache */
            $cache = $value[$endpoint];

            if ($cache['type'] === 'registry' && ! $this->isValidRegistryReference($cache['value'])) {
                $fail("The {$attribute}.{$endpoint}.value must be a valid explicit registry cache reference.");

                return;
            }

            if ($cache['type'] === 'raw' && ! $this->isSafeRawValue($cache['value'])) {
                $fail("The {$attribute}.{$endpoint}.value contains unsupported characters or is not a BuildKit cache value.");

                return;
            }

            if ($cache['type'] === 'raw' && ! $this->usesMountedLocalCachePath($endpoint, $cache['value'])) {
                $parameter = $endpoint === 'cache_from' ? 'src' : 'dest';
                $fail("A local {$attribute}.{$endpoint}.value must use {$parameter}=/cache or a path below /cache.");

                return;
            }
        }
    }

    private function isValidRegistryReference(string $reference): bool
    {
        return Validator::make(
            ['reference' => $reference],
            ['reference' => ['required', new DockerImageFormat]],
        )->passes();
    }

    private function isSafeRawValue(string $value): bool
    {
        if (preg_match('/\Atype=[a-z0-9][a-z0-9_-]*(?:,[A-Za-z0-9_.+\/:=@-]+)*\z/', $value) !== 1) {
            return false;
        }

        return str_contains($value, ',');
    }

    private function usesMountedLocalCachePath(string $endpoint, string $value): bool
    {
        if (! str_starts_with($value, 'type=local,')) {
            return true;
        }

        $parameterName = $endpoint === 'cache_from' ? 'src' : 'dest';
        $parameter = collect(explode(',', $value))->first(
            fn (string $item): bool => str_starts_with($item, $parameterName.'='),
        );

        if ($parameter === null) {
            return false;
        }

        $path = str($parameter)->after('=')->value();

        return $path === '/cache' || str_starts_with($path, '/cache/');
    }
}
