<?php

namespace App\Services\DeployTriggers\Contract;

final class Sha
{
    public static function isValid(mixed $value): bool
    {
        return is_string($value) && preg_match('/\A[0-9a-f]{40}([0-9a-f]{24})?\z/', $value) === 1;
    }
}
