<?php

namespace App\Services\ImageScan;

class GrypeReportParser
{
    private const MAX_BYTES = 10 * 1024 * 1024;

    private const SEVERITIES = ['critical', 'high', 'medium', 'low'];

    /**
     * @return array{findings: list<Finding>, truncated: bool, eol: ?array{name: string, version: string}}
     */
    public function parse(string $ndjson, int $limit = 5000): array
    {
        $findings = [];
        $truncated = false;
        $eol = null;

        if (strlen($ndjson) > self::MAX_BYTES) {
            return ['findings' => [], 'truncated' => true, 'eol' => null];
        }

        foreach (explode("\n", $ndjson) as $line) {
            $line = trim($line);
            if ($line === '' || ($truncated && ! str_starts_with($line, '{"eol"'))) {
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
            if ($finding === null) {
                continue;
            }

            if (count($findings) >= $limit) {
                $truncated = true;

                continue;
            }

            $findings[] = $finding;
        }

        return ['findings' => $findings, 'truncated' => $truncated, 'eol' => $eol];
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

        return new Finding(
            id: $id,
            aliases: array_values(array_unique($aliases)),
            severity: in_array($severity, self::SEVERITIES, true) ? $severity : 'unknown',
            package: ScannerText::clean($row['pkg'] ?? null, 214),
            version: ScannerText::clean($row['ver'] ?? null, 128),
            type: ScannerText::clean($row['type'] ?? null, 32),
            artifactId: ScannerText::clean($row['art'] ?? null, 64),
            fixedIn: $fixedIn !== '' ? $fixedIn : null,
            fixState: $fixState,
            kev: ($row['kev'] ?? false) === true,
            epss: is_numeric($row['epss'] ?? null) ? (float) $row['epss'] : null,
            risk: is_numeric($row['risk'] ?? null) ? (float) $row['risk'] : null,
            summary: ScannerText::clean($row['desc'] ?? null, 300),
        );
    }
}
