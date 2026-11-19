<?php

namespace App\Services\ImageScan;

final readonly class Package
{
    /**
     * @param  list<string>  $licenses
     */
    public function __construct(
        public string $id,
        public string $name,
        public string $version,
        public string $type,
        public array $licenses,
        public string $purl,
        public string $layerId,
    ) {}

    /**
     * @return array{id: string, name: string, version: string, type: string, licenses: list<string>, purl: string, layerId: string}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'version' => $this->version,
            'type' => $this->type,
            'licenses' => $this->licenses,
            'purl' => $this->purl,
            'layerId' => $this->layerId,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: (string) ($data['id'] ?? ''),
            name: (string) ($data['name'] ?? ''),
            version: (string) ($data['version'] ?? ''),
            type: (string) ($data['type'] ?? ''),
            licenses: array_values(array_map('strval', (array) ($data['licenses'] ?? []))),
            purl: (string) ($data['purl'] ?? ''),
            layerId: (string) ($data['layerId'] ?? ''),
        );
    }
}
