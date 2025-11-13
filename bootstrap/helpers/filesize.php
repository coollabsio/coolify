<?php

/**
 * Format bytes to human-readable size
 *
 * @param int|float $bytes The size in bytes
 * @param int $precision The number of decimal places
 * @return string Formatted size with unit (B, KB, MB, GB, TB)
 */
function formatBytes($bytes, int $precision = 2): string
{
    $bytes = (int) $bytes;

    if ($bytes < 1024) {
        return $bytes . ' B';
    }

    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $exp = floor(log($bytes) / log(1024));
    $exp = min($exp, count($units) - 1);

    $value = $bytes / pow(1024, $exp);

    return round($value, $precision) . ' ' . $units[$exp];
}
