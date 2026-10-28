<?php

namespace App\Services\ImageScan;

final readonly class Finding
{
    /**
     * @param  list<string>  $aliases
     */
    public function __construct(
        public string $id,
        public array $aliases,
        public string $severity,
        public string $package,
        public string $version,
        public string $type,
        public string $artifactId,
        public ?string $fixedIn,
        public string $fixState,
        public bool $kev,
        public ?float $epss,
        public ?float $risk,
        public string $summary,
        public string $layer = 'app',
        public ?float $cvssScore = null,
        public string $title = '',
        public string $displayId = '',
        public string $namespace = '',
        public string $matchType = '',
    ) {}

    public function withLayer(string $layer): self
    {
        return self::fromArray([...$this->toArray(), 'layer' => $layer]);
    }

    /**
     * @return array{id: string, aliases: list<string>, severity: string, package: string, version: string, type: string, artifactId: string, fixedIn: ?string, fixState: string, kev: bool, epss: ?float, risk: ?float, summary: string, layer: string, cvssScore: ?float, title: string, displayId: string, namespace: string, matchType: string}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'aliases' => $this->aliases,
            'severity' => $this->severity,
            'package' => $this->package,
            'version' => $this->version,
            'type' => $this->type,
            'artifactId' => $this->artifactId,
            'fixedIn' => $this->fixedIn,
            'fixState' => $this->fixState,
            'kev' => $this->kev,
            'epss' => $this->epss,
            'risk' => $this->risk,
            'summary' => $this->summary,
            'layer' => $this->layer,
            'cvssScore' => $this->cvssScore,
            'title' => $this->title,
            'displayId' => $this->displayId,
            'namespace' => $this->namespace,
            'matchType' => $this->matchType,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $id = (string) ($data['id'] ?? '');
        $aliases = array_values(array_map('strval', (array) ($data['aliases'] ?? [])));
        $severity = (string) ($data['severity'] ?? 'unknown');
        $package = (string) ($data['package'] ?? '');
        $version = (string) ($data['version'] ?? '');
        $title = ScannerText::tidyTitle(trim((string) ($data['title'] ?? '')));
        $displayId = (string) ($data['displayId'] ?? '');

        return new self(
            id: $id,
            aliases: $aliases,
            severity: $severity,
            package: $package,
            version: $version,
            type: (string) ($data['type'] ?? ''),
            artifactId: (string) ($data['artifactId'] ?? ''),
            fixedIn: isset($data['fixedIn']) ? (string) $data['fixedIn'] : null,
            fixState: (string) ($data['fixState'] ?? ''),
            kev: (bool) ($data['kev'] ?? false),
            epss: isset($data['epss']) ? (float) $data['epss'] : null,
            risk: isset($data['risk']) ? (float) $data['risk'] : null,
            summary: (string) ($data['summary'] ?? ''),
            layer: (string) ($data['layer'] ?? 'app'),
            cvssScore: is_numeric($data['cvssScore'] ?? null) ? (float) $data['cvssScore'] : null,
            title: $title !== '' ? $title : ScannerText::fallbackTitle($package, $version, $severity),
            displayId: $displayId !== '' ? $displayId : self::displayIdFor($id, $aliases),
            namespace: (string) ($data['namespace'] ?? ''),
            matchType: (string) ($data['matchType'] ?? ''),
        );
    }

    /**
     * A CVE ID from the finding ID or its aliases, else the finding ID.
     *
     * @param  list<string>  $aliases
     */
    public static function displayIdFor(string $id, array $aliases): string
    {
        foreach ([$id, ...$aliases] as $candidate) {
            if (preg_match('/^CVE-\d{4}-\d{4,}$/i', $candidate) === 1) {
                return $candidate;
            }
        }

        return $id;
    }
}
