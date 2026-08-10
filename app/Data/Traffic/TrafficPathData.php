<?php

namespace App\Data\Traffic;

use Spatie\LaravelData\Data;

class TrafficPathData extends Data
{
    public function __construct(
        public string $path,
        public int $requests,
        public int $bytesOut,
        public float $p50,
        public float $p95,
    ) {}

    public static function fromSentinel(array $row): self
    {
        return new self(
            path: (string) data_get($row, 'path', ''),
            requests: (int) data_get($row, 'requests', 0),
            bytesOut: (int) data_get($row, 'bytes_out', 0),
            p50: (float) data_get($row, 'p50', 0.0),
            p95: (float) data_get($row, 'p95', 0.0),
        );
    }
}
