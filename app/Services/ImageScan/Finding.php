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
    ) {}

    public function withLayer(string $layer): self
    {
        return new self(
            $this->id, $this->aliases, $this->severity, $this->package, $this->version, $this->type,
            $this->artifactId, $this->fixedIn, $this->fixState, $this->kev, $this->epss, $this->risk,
            $this->summary, $layer,
        );
    }

    /**
     * @return array{id: string, aliases: list<string>, severity: string, package: string, version: string, type: string, artifactId: string, fixedIn: ?string, fixState: string, kev: bool, epss: ?float, risk: ?float, summary: string, layer: string}
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
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: (string) ($data['id'] ?? ''),
            aliases: array_values(array_map('strval', (array) ($data['aliases'] ?? []))),
            severity: (string) ($data['severity'] ?? 'unknown'),
            package: (string) ($data['package'] ?? ''),
            version: (string) ($data['version'] ?? ''),
            type: (string) ($data['type'] ?? ''),
            artifactId: (string) ($data['artifactId'] ?? ''),
            fixedIn: isset($data['fixedIn']) ? (string) $data['fixedIn'] : null,
            fixState: (string) ($data['fixState'] ?? ''),
            kev: (bool) ($data['kev'] ?? false),
            epss: isset($data['epss']) ? (float) $data['epss'] : null,
            risk: isset($data['risk']) ? (float) $data['risk'] : null,
            summary: (string) ($data['summary'] ?? ''),
            layer: (string) ($data['layer'] ?? 'app'),
        );
    }
}
