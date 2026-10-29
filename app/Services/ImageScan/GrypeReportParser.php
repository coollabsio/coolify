<?php

namespace App\Services\ImageScan;

class GrypeReportParser
{
    private const MAX_BYTES = 10 * 1024 * 1024;

    private const SEVERITIES = ['critical', 'high', 'medium', 'low'];

    /**
     * The end-of-life line comes first in the current template and last in older outputs; both are read. Over $limit,
     * the findings with an exploit signal (KEV, then likely) are kept first, then the highest risk, so a cut report
     * keeps the findings that matter most.
     *
     * @return array{findings: list<Finding>, truncated: bool, eol: ?array{name: string, version: string}}
     */
    public function parse(string $ndjson, int $limit = 5000): array
    {
        $findings = [];
        $eol = null;

        if (strlen($ndjson) > self::MAX_BYTES) {
            return ['findings' => [], 'truncated' => true, 'eol' => null];
        }

        foreach (explode("\n", $ndjson) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            $row = json_decode($line, true, 8);
            if (! is_array($row)) {
                continue;
            }

            if (array_key_exists('eol', $row) && ! array_key_exists('id', $row)) {
                $eol = $this->eol($row['eol']);

                continue;
            }

            $finding = $this->finding($row);
            if ($finding !== null) {
                $findings[] = $finding;
            }
        }

        $truncated = count($findings) > $limit;
        if ($truncated) {
            $findings = array_slice($this->mostImportantFirst($findings), 0, $limit);
        }

        return ['findings' => $findings, 'truncated' => $truncated, 'eol' => $eol];
    }

    /**
     * KEV first, then likely to be exploited, then the highest risk; the report order breaks ties.
     *
     * @param  list<Finding>  $findings
     * @return list<Finding>
     */
    private function mostImportantFirst(array $findings): array
    {
        $rank = fn (Finding $finding): int => match (SecurityRules::exploitSignal($finding)) {
            SecurityRules::EXPLOITED => 0,
            SecurityRules::LIKELY => 1,
            default => 2,
        };
        $keyed = array_map(fn (Finding $finding, int $index): array => [$rank($finding), -($finding->risk ?? 0.0), $index, $finding], $findings, array_keys($findings));
        usort($keyed, fn (array $a, array $b): int => [$a[0], $a[1], $a[2]] <=> [$b[0], $b[1], $b[2]]);

        return array_map(fn (array $entry): Finding => $entry[3], $keyed);
    }

    /**
     * @return ?array{name: string, version: string}
     */
    private function eol(mixed $eol): ?array
    {
        if (! is_array($eol)) {
            return null;
        }

        $name = ScannerText::clean($eol['name'] ?? null, 64);
        if ($name === '') {
            return null;
        }

        return ['name' => $name, 'version' => ScannerText::clean($eol['version'] ?? null, 64)];
    }

    /**
     * @param  array<mixed>  $row
     */
    private function finding(array $row): ?Finding
    {
        $id = ScannerText::clean($row['id'] ?? null, 256);
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,63}$/', $id) !== 1) {
            return null;
        }

        $severity = strtolower(ScannerText::clean($row['sev'] ?? null, 16));
        $severity = $severity === 'negligible' ? 'low' : $severity;
        $fixState = ScannerText::clean($row['fix'] ?? null, 32);
        $fixVersions = is_array($row['fixv'] ?? null) ? array_values($row['fixv']) : [];
        $fixedIn = ScannerText::clean($row['sugg'] ?? null, 128);
        if ($fixedIn === '' && $fixState === 'fixed') {
            $fixedIn = ScannerText::clean($fixVersions[0] ?? null, 128);
        }

        $aliases = [];
        foreach (is_array($row['rel'] ?? null) ? $row['rel'] : [] as $related) {
            $alias = ScannerText::clean($related, 64);
            if ($alias !== '' && $alias !== $id) {
                $aliases[] = $alias;
            }
        }

        $aliases = array_values(array_unique($aliases));
        $severity = in_array($severity, self::SEVERITIES, true) ? $severity : 'unknown';
        $package = ScannerText::clean($row['pkg'] ?? null, 214);
        $version = ScannerText::clean($row['ver'] ?? null, 128);
        $namespace = ScannerText::clean($row['ns'] ?? null, 128);

        return new Finding(
            id: $id,
            aliases: $aliases,
            severity: $severity,
            package: $package,
            version: $version,
            type: ScannerText::clean($row['type'] ?? null, 32),
            artifactId: ScannerText::clean($row['art'] ?? null, 64),
            fixedIn: $fixedIn !== '' ? $fixedIn : null,
            fixState: $fixState,
            kev: ($row['kev'] ?? false) === true,
            epss: is_numeric($row['epss'] ?? null) ? (float) $row['epss'] : null,
            risk: is_numeric($row['risk'] ?? null) ? (float) $row['risk'] : null,
            summary: ScannerText::clean($row['desc'] ?? null, 300),
            cvssScore: $this->score($row['cvss'] ?? null, $row['rcvss'] ?? null, $namespace),
            title: ScannerText::title(
                is_string($row['desc'] ?? null) ? $row['desc'] : null,
                is_string($row['rdesc'] ?? null) ? $row['rdesc'] : null,
                $package,
                $version,
                $severity,
            ),
            displayId: Finding::displayIdFor($id, $aliases),
            namespace: $namespace,
            matchType: ScannerText::clean($row['mt'] ?? null, 64),
        );
    }

    /**
     * The base score of the finding's own source (every entry for an NVD namespace, otherwise the entries that
     * are not from NVD), then the NVD score of the finding or its first related vulnerability. Within a source
     * the highest CVSS version wins (4.x, 3.1, 3.0); v2 is ignored.
     */
    private function score(mixed $own, mixed $related, string $namespace): ?float
    {
        $own = $this->scoreEntries($own);
        $related = $this->scoreEntries($related);
        $isNvdNamespace = str_starts_with(strtolower($namespace), 'nvd');

        $groups = [
            $isNvdNamespace ? $own : array_filter($own, fn (array $entry): bool => ! $entry['nvd']),
            array_filter([...$own, ...$related], fn (array $entry): bool => $entry['nvd']),
        ];

        foreach ($groups as $group) {
            $best = null;
            foreach ($group as $entry) {
                if ($best === null || $entry['version'] > $best['version'] || ($entry['version'] === $best['version'] && $entry['nvd'] && ! $best['nvd'])) {
                    $best = $entry;
                }
            }

            if ($best !== null) {
                return $best['score'];
            }
        }

        return null;
    }

    /**
     * @return list<array{nvd: bool, version: float, score: float}>
     */
    private function scoreEntries(mixed $entries): array
    {
        $result = [];
        foreach (is_array($entries) ? array_slice($entries, 0, 32) : [] as $entry) {
            if (! is_array($entry) || ! is_numeric($entry['score'] ?? null) || ! is_numeric($entry['version'] ?? null)) {
                continue;
            }

            $score = (float) $entry['score'];
            $version = (float) $entry['version'];
            if ($version < 3.0 || $score <= 0.0 || $score > 10.0) {
                continue;
            }

            $result[] = [
                'nvd' => str_contains(strtolower(ScannerText::clean($entry['source'] ?? null, 128)), 'nvd'),
                'version' => $version,
                'score' => $score,
            ];
        }

        return $result;
    }
}
