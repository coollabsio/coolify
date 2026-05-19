<?php

namespace App\Services\Kubernetes;

use Symfony\Component\Yaml\Yaml;

class KubernetesManifestData
{
    public function stringList(?string $value): array
    {
        return collect(preg_split('/[\r\n,]+/', (string) $value))
            ->map(fn (string $item) => trim($item))
            ->filter()
            ->unique()
            ->values()
            ->toArray();
    }

    public function keyValueMap(?string $value): array
    {
        return collect(preg_split('/[\r\n]+/', (string) $value))
            ->map(fn (string $line) => trim($line))
            ->filter()
            ->mapWithKeys(function (string $line) {
                $separator = str_contains($line, '=') ? '=' : ':';
                [$key, $value] = array_pad(explode($separator, $line, 2), 2, '');

                $key = trim($key);
                $value = trim($value);

                return $key === '' ? [] : [$key => $value];
            })
            ->toArray();
    }

    public function yamlList(?string $value): array
    {
        if (blank($value)) {
            return [];
        }

        $parsed = Yaml::parse($value);

        if (! is_array($parsed)) {
            return [];
        }

        if (array_is_list($parsed)) {
            return collect($parsed)->filter(fn ($item) => is_array($item))->values()->toArray();
        }

        return [$parsed];
    }

    public function intOrPercent(null|int|string $value, int $default): int|string
    {
        if (is_string($value) && preg_match('/^\d+%$/', $value) === 1) {
            return $value;
        }

        if (is_numeric($value)) {
            return (int) $value;
        }

        return $default;
    }

    public function dnsLabel(string $value, string $fallback = 'resource', int $maxLength = 63): string
    {
        $label = str($value)
            ->lower()
            ->replaceMatches('/[^a-z0-9-]+/', '-')
            ->replaceMatches('/-+/', '-')
            ->trim('-')
            ->toString();

        if ($label === '') {
            $label = $fallback;
        }

        return trim(substr($label, 0, $maxLength), '-') ?: $fallback;
    }
}
