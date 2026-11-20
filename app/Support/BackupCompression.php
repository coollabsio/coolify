<?php

namespace App\Support;

final class BackupCompression
{
    public static function cpuPercentage(int|string|null $configuredPercentage): int
    {
        $percentage = (int) $configuredPercentage;

        return in_array($percentage, [25, 50, 75, 100], true) ? $percentage : 25;
    }

    public static function compressorCommand(int $cpuPercentage): string
    {
        $cpuPercentage = self::cpuPercentage($cpuPercentage);

        return "if command -v pigz >/dev/null 2>&1; then printf 'pigz -3 -p %s' \"\$(( (\$(nproc) * {$cpuPercentage} + 99) / 100 ))\"; else printf 'gzip -3'; fi";
    }
}
