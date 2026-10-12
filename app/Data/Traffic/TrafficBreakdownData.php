<?php

namespace App\Data\Traffic;

use Spatie\LaravelData\Data;

class TrafficBreakdownData extends Data
{
    public function __construct(
        public string $value,
        public int $requests,
        public int $bytesOut,
        public ?float $lat = null,
        public ?float $lon = null,
    ) {}

    public static function fromSentinel(array $row): self
    {
        $lat = data_get($row, 'lat');
        $lon = data_get($row, 'lon');
        $hasCoordinates = is_numeric($lat) && is_numeric($lon);

        return new self(
            value: (string) data_get($row, 'value', ''),
            requests: (int) data_get($row, 'requests', 0),
            bytesOut: (int) data_get($row, 'bytes_out', 0),
            lat: $hasCoordinates ? (float) $lat : null,
            lon: $hasCoordinates ? (float) $lon : null,
        );
    }
}
