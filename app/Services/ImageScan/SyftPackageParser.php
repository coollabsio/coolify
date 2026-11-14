<?php

namespace App\Services\ImageScan;

class SyftPackageParser
{
    private const MAX_BYTES = 10 * 1024 * 1024;

    private const MAX_LICENSES = 32;

    /**
     * Rows without a package `id` (such as the `{"layers":…}` trailer of older templates) are skipped.
     *
     * @return array{packages: list<Package>, truncated: bool}
     */
    public function parse(string $ndjson, int $limit = 10000): array
    {
        $packages = [];
        $truncated = false;

        if (strlen($ndjson) > self::MAX_BYTES) {
            return ['packages' => [], 'truncated' => true];
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

            $package = $this->package($row);
            if ($package === null) {
                continue;
            }

            if (count($packages) >= $limit) {
                $truncated = true;

                break;
            }

            $packages[] = $package;
        }

        return ['packages' => $packages, 'truncated' => $truncated];
    }

    /**
     * @param  array<mixed>  $row
     */
    private function package(array $row): ?Package
    {
        $id = ScannerText::clean($row['id'] ?? null, 64);
        $name = ScannerText::clean($row['name'] ?? null, 214);
        if ($id === '' || $name === '') {
            return null;
        }

        $licenses = [];
        foreach (is_array($row['licenses'] ?? null) ? $row['licenses'] : [] as $license) {
            $license = ScannerText::clean($license, 128);
            if ($license !== '' && count($licenses) < self::MAX_LICENSES) {
                $licenses[] = $license;
            }
        }

        return new Package(
            id: $id,
            name: $name,
            version: ScannerText::clean($row['version'] ?? null, 128),
            type: ScannerText::clean($row['type'] ?? null, 32),
            licenses: $licenses,
            purl: ScannerText::clean($row['purl'] ?? null, 512),
            layerId: ScannerText::clean($row['layer'] ?? null, 80),
        );
    }
}
