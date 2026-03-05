<?php

$lines = [
    '-rw-r--r-- 1 root root 1073741824 Mar 4 10:30 wp-content.zip', // normal
    '-rw-r--r--    1 root     root             1073741824 Mar  4 10:30 wp-content.zip', // alpine
    '-rw-r--r-- 1 root root 1,073,741,824 Mar 4 10:30 wp-content.zip', // commas
    '-rw-r--r-- 1 root root 1.0G Mar 4 10:30 wp-content.zip', // human
    '-rw-r--r-- 1 root root 1073741824 Mar  4  2024 wp-content.zip', // older file
];

function formatSize(int $bytes): string
{
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $i = 0;
    while ($bytes >= 1024 && $i < count($units) - 1) {
        $bytes /= 1024;
        $i++;
    }
    return round($bytes, 2).' '.$units[$i];
}

foreach ($lines as $line) {
    echo "Line: $line\n";
    if (preg_match('/^([d\-])([rwx\-]+)\s+\d+\s+\S+\s+\S+\s+(\S+)\s+(\S+\s+\d+\s+[\d:]+)\s+(.+)$/', $line, $matches)) {
        $sizeRaw = $matches[3];
        $sizeInt = (int) $sizeRaw;
        $formatted = formatSize($sizeInt);
        echo "  Match: sizeRaw='$sizeRaw' -> sizeInt=$sizeInt -> formatted='$formatted'\n";
    } else {
        echo "  NO MATCH\n";
    }
}
