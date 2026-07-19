<?php

namespace App\Support\V5;

class V5Feature
{
    private const DEVELOPMENT_ENVIRONMENTS = ['local', 'development', 'dev', 'testing'];

    public static function enabled(): bool
    {
        return (bool) config('v5.enabled');
    }

    public static function enabledForEnvironment(string $environment): bool
    {
        return in_array($environment, self::DEVELOPMENT_ENVIRONMENTS, true);
    }
}
