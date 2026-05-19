<?php

namespace App\Services\DeploymentConfiguration;

class ConfigurationDiffer
{
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
            $previous = $previousItems[$key] ?? null;
            $current = $currentItems[$key] ?? null;

            if (($previous['compare_value'] ?? null) === ($current['compare_value'] ?? null)) {
                continue;
            }

            $item = $current ?? $previous;
            $sensitive = (bool) data_get($item, 'sensitive', false);
            $type = $previous === null ? 'added' : ($current === null ? 'removed' : 'changed');
            $displaySummary = $sensitive && $type === 'changed' ? 'Changed' : null;

            $changes[] = [
                'key' => $key,
                'section' => data_get($item, 'section'),
                'section_label' => data_get($item, 'section_label'),
                'label' => data_get($item, 'label'),
                'type' => $type,
                'impact' => data_get($item, 'impact', 'redeploy'),
                'sensitive' => $sensitive,
                'display_summary' => $displaySummary,
                'old_display_value' => $sensitive ? ($previous === null ? 'Not set' : 'Set') : data_get($previous, 'display_value', 'Not set'),
                'new_display_value' => $sensitive ? ($current === null ? 'Removed' : 'Set') : data_get($current, 'display_value', 'Not set'),
            ];
        }

        return ConfigurationDiff::fromChanges($changes);
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
