<?php

namespace App\Support\Metrics;

class PressureLevel
{
    public static function for(?float $percent, string $kind = 'default'): string
    {
        if ($percent === null) {
            return 'ok';
        }

        [$warn, $error] = $kind === 'disk' ? [80.0, 90.0] : [70.0, 90.0];

        return match (true) {
            $percent >= $error => 'error',
            $percent >= $warn => 'warning',
            default => 'ok',
        };
    }

    public static function textClass(string $level): string
    {
        return match ($level) {
            'error' => 'text-red-500 dark:text-red-400',
            'warning' => 'text-orange-500 dark:text-warning',
            default => 'text-black dark:text-fg',
        };
    }

    public static function barClass(string $level): string
    {
        return match ($level) {
            'error' => 'bg-red-500 dark:bg-red-400',
            'warning' => 'bg-orange-500 dark:bg-warning',
            default => 'bg-neutral-400 dark:bg-white/30',
        };
    }
}
