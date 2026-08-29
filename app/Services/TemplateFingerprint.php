<?php

namespace App\Services;

use Symfony\Component\Yaml\Yaml;
use Throwable;

class TemplateFingerprint
{
    public static function hash(string $composeYaml): string
    {
        try {
            $parsed = Yaml::parse($composeYaml);
            if (! is_array($parsed)) {
                return hash('sha256', trim($composeYaml));
            }
            self::ksortRecursive($parsed);

            return hash('sha256', json_encode($parsed));
        } catch (Throwable) {
            return hash('sha256', trim($composeYaml));
        }
    }

    public static function forTemplate(array|object $template): ?string
    {
        $compose = data_get($template, 'compose');
        if (! is_string($compose) || $compose === '') {
            return null;
        }

        $decoded = base64_decode($compose, true);
        if ($decoded === false) {
            return null;
        }

        return self::hash($decoded);
    }

    private static function ksortRecursive(array &$array): void
    {
        ksort($array);
        foreach ($array as &$value) {
            if (is_array($value)) {
                self::ksortRecursive($value);
            }
        }
    }
}
