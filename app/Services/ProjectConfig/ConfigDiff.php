<?php

namespace App\Services\ProjectConfig;

/**
 * Compares two file-form resources. Lists are compared as whole values. Each entry of a child
 * group (one variable, one volume, one file, one task) is compared as one value.
 */
final class ConfigDiff
{
    public const UNIT_GROUPS = ['env', 'storages', 'files', 'scheduled_tasks'];

    /**
     * @return list<array{path: string, from: mixed, to: mixed}>
     */
    public static function between(array $current, array $desired): array
    {
        return self::walk($current, $desired, '');
    }

    private static function walk(array $current, array $desired, string $base): array
    {
        $differences = [];
        $keys = array_unique([...array_keys($current), ...array_keys($desired)]);
        sort($keys);

        foreach ($keys as $key) {
            $path = $base === '' ? (string) $key : "{$base}.{$key}";
            $from = $current[$key] ?? null;
            $to = $desired[$key] ?? null;
            if ($from === $to) {
                continue;
            }
            if ($base === '' && in_array($key, self::UNIT_GROUPS, true)) {
                $differences = [...$differences, ...self::units((array) $from, (array) $to, $path)];

                continue;
            }
            if (self::isMap($from) && self::isMap($to)) {
                $differences = [...$differences, ...self::walk($from, $to, $path)];

                continue;
            }
            $differences[] = ['path' => $path, 'from' => $from, 'to' => $to];
        }

        return $differences;
    }

    private static function units(array $current, array $desired, string $base): array
    {
        $differences = [];
        $keys = array_unique([...array_keys($current), ...array_keys($desired)]);
        sort($keys);
        foreach ($keys as $key) {
            $from = $current[$key] ?? null;
            $to = $desired[$key] ?? null;
            if ($from !== $to) {
                $differences[] = ['path' => "{$base}.{$key}", 'from' => $from, 'to' => $to];
            }
        }

        return $differences;
    }

    private static function isMap(mixed $value): bool
    {
        return is_array($value) && $value !== [] && ! array_is_list($value);
    }
}
