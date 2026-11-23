<?php

namespace App\Services\ProjectConfig;

/**
 * Turns the stored changes of a plan into one row per resource for the UI.
 */
final class ConfigPlanView
{
    private const TAGS = [
        'create' => 'New',
        'update' => 'Changed',
        'remove' => 'Removed',
        'rename' => 'Renamed',
        'import' => 'Adopted',
        'unmanage' => 'No longer managed',
    ];

    /** Groups whose entries are records: a missing value means added or removed, not the default. */
    private const RECORD_GROUPS = ['env', 'storages', 'files', 'scheduled_tasks', 'backups', 'services'];

    /**
     * @param  list<array<string, mixed>>  $changes
     * @return list<array{key: string, kind: string, tag: string, destructive: bool, removes: bool, detail: ?string, fields: list<array{path: string, value: string, destructive: bool}>}>
     */
    /**
     * The plan without stored and new values. Commands, Compose, and file content can hold credentials,
     * so only users who can read sensitive data see them.
     *
     * @param  array<string, mixed>|null  $plan
     * @return array<string, mixed>|null
     */
    public static function withoutValues(?array $plan): ?array
    {
        if ($plan === null) {
            return null;
        }
        $plan['changes'] = array_map(fn (array $change) => ['secret' => true, 'from' => null, 'to' => null] + $change, $plan['changes'] ?? []);

        return $plan;
    }

    public static function groups(array $changes): array
    {
        $groups = [];
        foreach ($changes as $change) {
            $key = (string) ($change['key'] ?? '');
            $kind = (string) ($change['kind'] ?? 'update');
            $groups[$key] ??= ['key' => $key, 'kind' => $kind, 'tag' => self::TAGS[$kind] ?? 'Changed', 'destructive' => false, 'removes' => false, 'detail' => null, 'fields' => []];
            $group = &$groups[$key];

            if ($kind === 'update' && ($change['path'] ?? '') !== '') {
                $group['fields'][] = [
                    'path' => (string) $change['path'],
                    'value' => ($change['secret'] ?? false) ? 'changed' : self::describe((string) $change['path'], $change['from'] ?? null, $change['to'] ?? null),
                    'destructive' => (bool) ($change['destructive'] ?? false),
                ];
            } elseif ($group['kind'] !== $kind && in_array($kind, ['create', 'remove'], true) && in_array($group['kind'], ['create', 'remove'], true)) {
                $group['kind'] = 'replace';
                $group['tag'] = 'Replaced';
            } elseif ($group['fields'] === [] || $kind !== 'update') {
                $group['kind'] = $group['kind'] === 'update' ? $kind : $group['kind'];
                $group['tag'] = self::TAGS[$group['kind']] ?? $group['tag'];
            }

            if ($kind === 'rename') {
                $group['detail'] = 'from '.($change['from'] ?? '');
            }
            if ($kind === 'remove') {
                $group['removes'] = true;
            }
            $group['destructive'] = $group['destructive'] || (bool) ($change['destructive'] ?? false);
            unset($group);
        }

        return array_values($groups);
    }

    private static function describe(string $path, mixed $from, mixed $to): string
    {
        $isRecord = in_array(explode('.', $path)[0], self::RECORD_GROUPS, true);

        return self::value($from, $isRecord ? 'none' : 'default').' → '.self::value($to, $isRecord ? 'removed' : 'default');
    }

    private static function value(mixed $value, string $empty): string
    {
        if ($value === null || $value === [] || $value === '') {
            return $empty;
        }
        if (is_bool($value)) {
            return $value ? 'on' : 'off';
        }
        if (is_array($value)) {
            if (array_is_list($value)) {
                return implode(', ', array_map(fn ($item) => is_scalar($item) ? (string) $item : json_encode($item), $value));
            }
            $parts = [];
            foreach ($value as $key => $item) {
                $parts[] = "{$key}: ".(is_scalar($item) ? (is_bool($item) ? ($item ? 'on' : 'off') : (string) $item) : json_encode($item));
            }

            return implode(', ', $parts);
        }

        return (string) $value;
    }
}
