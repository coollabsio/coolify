<?php

namespace App\Data\Traffic;

use Spatie\LaravelData\Data;

class TrafficBreakdownData extends Data
{
    public function __construct(
        public string $value,
        public int $requests,
        public int $bytesOut,
    ) {}

    public static function fromSentinel(array $row): self
    {
        return new self(
            value: (string) data_get($row, 'value', ''),
            requests: (int) data_get($row, 'requests', 0),
            bytesOut: (int) data_get($row, 'bytes_out', 0),
        );
    }
}
