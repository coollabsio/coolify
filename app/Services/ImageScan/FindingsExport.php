<?php

namespace App\Services\ImageScan;

use App\Models\ImageScanIgnore;

/**
 * Turns the findings that the Vulnerabilities list shows into text to copy or a file to download: one entry per finding.
 *
 * @phpstan-type ExportRow array{finding: Finding, state: string, ignore: ?ImageScanIgnore, image: string, id?: string}
 */
final class FindingsExport
{
    /**
     * A Markdown table for an issue, a pull request, or a chat. The image column shows only for more than one image.
     *
     * @param  list<ExportRow>  $rows
     */
    public static function markdown(string $title, array $rows, bool $withImage, bool $ignored): string
    {
        $header = ['ID', 'Severity', 'Package', 'Version', 'Fixed in'];
        if ($withImage) {
            $header[] = 'Image';
        }
        if ($ignored) {
            $header[] = 'Ignored because';
        }

        $lines = array_map(function (array $row) use ($withImage, $ignored): array {
            $finding = $row['finding'];
            $id = self::id($row);
            $cells = [
                ExportCells::markdownLink($id, ResourceScans::advisoryUrl($id)),
                self::severity($finding).($finding->kev ? ', known exploited' : ''),
                ExportCells::markdown($finding->package),
                ExportCells::markdown($finding->version),
                ExportCells::markdown((string) $finding->fixedIn),
            ];
            if ($withImage) {
                $cells[] = ExportCells::markdown($row['image']);
            }
            if ($ignored) {
                $cells[] = ExportCells::markdown((string) $row['ignore']?->reason);
            }

            return $cells;
        }, $rows);

        return ExportCells::markdownTable($title, $header, $lines);
    }

    /**
     * The finding IDs, once each, one per line.
     *
     * @param  list<ExportRow>  $rows
     */
    public static function ids(array $rows): string
    {
        $ids = array_values(array_unique(array_map(self::id(...), $rows)));

        return $ids === [] ? '' : implode("\n", $ids)."\n";
    }

    /**
     * @param  list<ExportRow>  $rows
     * @param  array<string, string>  $stateLabels
     */
    public static function csv(array $rows, array $stateLabels): string
    {
        return ExportCells::csvDocument(
            ['id', 'severity', 'known_exploited', 'package', 'version', 'fixed_in', 'package_type', 'image', 'fix_state', 'epss', 'cvss', 'aliases', 'ignored_reason', 'ignored_until', 'url', 'summary'],
            array_map(fn (array $entry): array => [
                $entry['id'], $entry['severity'], $entry['known_exploited'], $entry['package'], $entry['version'], $entry['fixed_in'],
                $entry['package_type'], $entry['image'], $entry['fix_state'], $entry['epss'], $entry['cvss'], implode(' ', $entry['aliases']),
                $entry['ignored']['reason'] ?? null, $entry['ignored']['until'] ?? null, $entry['url'], $entry['summary'],
            ], self::entries($rows, $stateLabels)),
        );
    }

    /**
     * @param  array<string, mixed>  $resource
     * @param  list<ExportRow>  $rows
     * @param  array<string, string>  $stateLabels
     */
    public static function json(array $resource, array $rows, array $stateLabels): string
    {
        return json_encode([
            'resource' => $resource,
            'exported_at' => now()->toIso8601String(),
            'count' => count($rows),
            'findings' => self::entries($rows, $stateLabels),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
    }

    /**
     * @param  list<ExportRow>  $rows
     * @param  array<string, string>  $stateLabels
     * @return list<array{id: string, aliases: list<string>, severity: string, known_exploited: bool, package: string, version: string, fixed_in: ?string, package_type: string, image: string, fix_state: string, epss: ?float, cvss: ?float, url: ?string, summary: string, ignored: ?array{reason: string, until: ?string}}>
     */
    private static function entries(array $rows, array $stateLabels): array
    {
        return array_map(function (array $row) use ($stateLabels): array {
            $finding = $row['finding'];
            $ignore = $row['ignore'];
            $id = self::id($row);

            return [
                'id' => $id,
                'aliases' => array_values(array_filter(array_unique([$finding->id, ...$finding->aliases]), fn (string $alias): bool => $alias !== $id)),
                'severity' => self::severity($finding),
                'known_exploited' => $finding->kev,
                'package' => $finding->package,
                'version' => $finding->version,
                'fixed_in' => $finding->fixedIn === '' ? null : $finding->fixedIn,
                'package_type' => $finding->type,
                'image' => $row['image'],
                'fix_state' => $stateLabels[$row['state']] ?? $row['state'],
                'epss' => $finding->epss,
                'cvss' => $finding->cvssScore,
                'url' => ResourceScans::advisoryUrl($id),
                'summary' => $finding->summary,
                'ignored' => $ignore === null ? null : [
                    'reason' => (string) $ignore->reason,
                    'until' => $ignore->expires_at?->toDateString(),
                ],
            ];
        }, $rows);
    }

    /**
     * The ID that the page shows: the host page shows the display ID of a finding.
     *
     * @param  ExportRow  $row
     */
    private static function id(array $row): string
    {
        return $row['id'] ?? $row['finding']->id;
    }

    private static function severity(Finding $finding): string
    {
        $severity = strtolower($finding->severity);

        return in_array($severity, ['critical', 'high', 'medium', 'low'], true) ? ucfirst($severity) : 'Unknown';
    }
}
