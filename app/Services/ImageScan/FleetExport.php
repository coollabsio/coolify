<?php

namespace App\Services\ImageScan;

use Illuminate\Support\Collection;

/**
 * Turns the resources that the team Security page shows into text to copy or a file to download: one entry per
 * resource, with its status and action, or with its best match while the page searches for a vulnerability.
 */
final class FleetExport
{
    /**
     * @param  Collection<int, array<string, mixed>>  $rows  Entries of TeamVulnerabilities, with `match` while searching.
     * @param  array<string, string>  $stateLabels
     * @return list<array<string, mixed>>
     */
    public static function entries(Collection $rows, bool $searching, array $stateLabels): array
    {
        return $rows->map(function (array $entry) use ($searching, $stateLabels): array {
            $security = $entry['security'];
            $checkedAt = $security->checkedAt ?? $entry['scans']->max('scanned_at');
            $values = [
                'resource' => (string) $entry['resource']->name,
                'type' => str(class_basename($entry['resource']))->snake()->toString(),
                'project' => $entry['project']?->name,
                'environment' => $entry['environment']?->name,
                'server' => $entry['server']?->name,
                'status' => TeamVulnerabilities::statusLabel($security->status),
            ];

            if ($searching) {
                $match = $entry['match'];
                $values += [
                    'match_id' => $match['id'],
                    'severity' => ucfirst($match['severity']),
                    'package' => $match['package'],
                    'version' => $match['version'],
                    'state' => $stateLabels[$match['state']] ?? $match['state'],
                    'fixed_in' => filled($match['fixedIn']) ? $match['fixedIn'] : null,
                    'other_matches' => $match['more'],
                ];
            } else {
                $values += [
                    'action' => TeamVulnerabilities::action($security)['text'] ?? null,
                    'images' => TeamVulnerabilities::imagesLine($security, $entry['images']),
                ];
            }

            return $values + [
                'checked_at' => $checkedAt?->toIso8601String(),
                'url' => $entry['link'],
            ];
        })->values()->all();
    }

    /**
     * @param  list<array<string, mixed>>  $entries
     */
    public static function markdown(string $title, array $entries, bool $searching): string
    {
        $header = $searching
            ? ['Resource', 'Project / Environment', 'Server', 'Match', 'State']
            : ['Resource', 'Project / Environment', 'Server', 'Status', 'Action'];

        $rows = array_map(fn (array $entry): array => [
            ExportCells::markdownLink($entry['resource'], $entry['url']),
            ExportCells::markdown(trim(($entry['project'] ?? '').' / '.($entry['environment'] ?? ''), ' /')),
            ExportCells::markdown((string) $entry['server']),
            ...($searching
                ? [
                    ExportCells::markdown("{$entry['match_id']} ({$entry['severity']}) {$entry['package']} {$entry['version']}".($entry['other_matches'] > 0 ? ", +{$entry['other_matches']} more" : '')),
                    ExportCells::markdown($entry['state'].($entry['fixed_in'] !== null ? " → {$entry['fixed_in']}" : '')),
                ]
                : [
                    ExportCells::markdown($entry['status']),
                    ExportCells::markdown((string) $entry['action']),
                ]),
        ], $entries);

        return ExportCells::markdownTable($title, $header, $rows);
    }

    /**
     * @param  list<array<string, mixed>>  $entries
     */
    public static function csv(array $entries, bool $searching): string
    {
        $header = $searching
            ? ['resource', 'type', 'project', 'environment', 'server', 'status', 'match_id', 'severity', 'package', 'version', 'state', 'fixed_in', 'other_matches', 'checked_at', 'url']
            : ['resource', 'type', 'project', 'environment', 'server', 'status', 'action', 'images', 'checked_at', 'url'];

        return ExportCells::csvDocument($header, array_map(
            fn (array $entry): array => array_map(fn (string $column) => $entry[$column] ?? null, $header),
            $entries,
        ));
    }

    /**
     * @param  array<string, mixed>  $meta
     * @param  list<array<string, mixed>>  $entries
     */
    public static function json(array $meta, array $entries): string
    {
        return json_encode([
            ...$meta,
            'exported_at' => now()->toIso8601String(),
            'count' => count($entries),
            'resources' => $entries,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n";
    }
}
