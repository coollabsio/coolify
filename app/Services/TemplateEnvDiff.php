<?php

namespace App\Services;

use App\Models\Service;

class TemplateEnvDiff
{
    /**
     * @return array{new: array<int, array{key:string,value:string}>, changed: array<int, array{key:string,template:string,current:string}>, removed: array<int, array{key:string}>}
     */
    public static function compute(array|object $template, Service $service): array
    {
        $templateEnvs = self::parseTemplateEnvs($template);
        $serviceEnvs = $service->environment_variables
            ->reject(fn ($env) => self::isMagic($env->key))
            ->keyBy('key');

        $new = [];
        $changed = [];
        foreach ($templateEnvs as $key => $value) {
            if (! $serviceEnvs->has($key)) {
                $new[] = ['key' => $key, 'value' => $value];

                continue;
            }
            $current = (string) $serviceEnvs->get($key)->value;
            if ($current !== $value) {
                $changed[] = ['key' => $key, 'template' => $value, 'current' => $current];
            }
        }

        $removed = [];
        foreach ($serviceEnvs as $key => $env) {
            if (! array_key_exists($key, $templateEnvs)) {
                $removed[] = ['key' => $key];
            }
        }

        return ['new' => $new, 'changed' => $changed, 'removed' => $removed];
    }

    /** @return array<string, string> */
    private static function parseTemplateEnvs(array|object $template): array
    {
        $raw = data_get($template, 'envs');
        if (! is_string($raw) || $raw === '') {
            return [];
        }
        $decoded = base64_decode($raw);
        $result = [];
        foreach (preg_split('/\r\n|\r|\n/', $decoded) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || ! str_contains($line, '=')) {
                continue;
            }
            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            if ($key === '' || self::isMagic($key)) {
                continue;
            }
            $result[$key] = $value;
        }

        return $result;
    }

    private static function isMagic(string $key): bool
    {
        return str_starts_with($key, 'SERVICE_');
    }
}
