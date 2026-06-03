<?php

namespace App\Services\DeploymentConfiguration;

use App\Services\DeploymentConfiguration\Concerns\SummarizesDiffText;

class ConfigurationDiffer
{
    use SummarizesDiffText;

    /**
     * Keys that must never be reported as changes. The generated docker_compose
     * is re-rendered from git on every parse, so legacy snapshots that still
     * contain it would otherwise flag a permanent change after it was dropped.
     *
     * @var array<int, string>
     */
    private const IGNORED_KEYS = ['build.docker_compose'];

    /**
     * @param  array<string, mixed>  $previousSnapshot
     * @param  array<string, mixed>  $currentSnapshot
     */
    public function diff(array $previousSnapshot, array $currentSnapshot): ConfigurationDiff
    {
        $previousItems = $this->flattenItems($previousSnapshot);
        $currentItems = $this->flattenItems($currentSnapshot);
        $keys = collect(array_keys($previousItems))->merge(array_keys($currentItems))->unique()->sort();
        $changes = [];

        foreach ($keys as $key) {
            if (in_array($key, self::IGNORED_KEYS, true)) {
                continue;
            }

            $previous = $previousItems[$key] ?? null;
            $current = $currentItems[$key] ?? null;

            if (($previous['compare_value'] ?? null) === ($current['compare_value'] ?? null)) {
                continue;
            }

            $item = $current ?? $previous;
            $sensitive = (bool) data_get($item, 'sensitive', false);
            $type = $previous === null ? 'added' : ($current === null ? 'removed' : 'changed');
            $displaySummary = $sensitive && $type === 'changed' ? 'Changed' : null;
            $diffMode = data_get($item, 'diff_mode', 'default');

            $oldFull = null;
            $newFull = null;

            if ($sensitive) {
                $oldDisplay = $previous === null ? '-' : '••••••••';
                $newDisplay = $current === null ? '-' : '••••••••';
            } elseif ($diffMode === 'lines' && $type === 'changed') {
                [$oldDisplay, $newDisplay] = $this->changedLines(
                    data_get($previous, 'display_full'),
                    data_get($current, 'display_full'),
                );

                // No line-level difference (e.g. only reordering) — fall back to the summary.
                if ($oldDisplay === '-' && $newDisplay === '-') {
                    $oldDisplay = data_get($previous, 'display_value', '-');
                    $newDisplay = data_get($current, 'display_value', '-');
                }

                // Expansion reveals the full changed lines, not the entire value.
                $oldFull = $this->expandableText($oldDisplay);
                $newFull = $this->expandableText($newDisplay);
            } else {
                $oldDisplay = data_get($previous, 'display_value', '-');
                $newDisplay = data_get($current, 'display_value', '-');
                $oldFull = data_get($previous, 'display_full');
                $newFull = data_get($current, 'display_full');
            }

            $expandable = ! $sensitive && (filled($oldFull) || filled($newFull));

            $changes[] = [
                'key' => $key,
                'section' => data_get($item, 'section'),
                'section_label' => data_get($item, 'section_label'),
                'label' => data_get($item, 'label'),
                'type' => $type,
                'impact' => data_get($item, 'impact', 'redeploy'),
                'sensitive' => $sensitive,
                'display_summary' => $displaySummary,
                'old_display_value' => $oldDisplay,
                'new_display_value' => $newDisplay,
                'old_full_value' => $oldFull,
                'new_full_value' => $newFull,
                'expandable' => $expandable,
            ];
        }

        return ConfigurationDiff::fromChanges($changes);
    }

    /**
     * Reduce two multi-line values to only the lines that differ, so the modal
     * shows just the changed container labels instead of the whole block.
     *
     * @return array{0: string, 1: string}
     */
    private function changedLines(?string $old, ?string $new): array
    {
        $oldLines = $this->textLines($old);
        $newLines = $this->textLines($new);

        $removed = array_values(array_diff($oldLines, $newLines));
        $added = array_values(array_diff($newLines, $oldLines));

        return [
            $removed === [] ? '-' : implode("\n", $removed),
            $added === [] ? '-' : implode("\n", $added),
        ];
    }

    /**
     * @return array<int, string>
     */
    private function textLines(?string $value): array
    {
        if (blank($value)) {
            return [];
        }

        // Keep leading indentation (meaningful for YAML/compose), drop trailing whitespace.
        return collect(preg_split('/\r\n|\r|\n/', (string) $value))
            ->map(fn (string $line): string => rtrim($line))
            ->filter(fn (string $line): bool => trim($line) !== '')
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @return array<string, array<string, mixed>>
     */
    private function flattenItems(array $snapshot): array
    {
        return collect(data_get($snapshot, 'sections', []))
            ->flatMap(function (array $section, string $sectionKey): array {
                return collect(data_get($section, 'items', []))
                    ->mapWithKeys(function (array $item) use ($section, $sectionKey): array {
                        $key = $sectionKey.'.'.$item['key'];

                        return [$key => array_merge($item, [
                            'section' => $sectionKey,
                            'section_label' => data_get($section, 'label', str($sectionKey)->headline()->value()),
                        ])];
                    })
                    ->all();
            })
            ->all();
    }
}
