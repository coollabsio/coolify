<?php

namespace App\Data\Traffic;

use Spatie\LaravelData\Data;

class TrafficSeriesBucketData extends Data
{
    public function __construct(
        // Unix-millis start of the bucket (hour- or day-aligned by the request's range).
        public int $bucket,
        public int $s2xx,
        public int $s3xx,
        public int $s4xx,
        public int $s5xx,
        // Per-bucket aggregates, so every KPI card can draw a real sparkline.
        public int $requests = 0,
        public int $bytesIn = 0,
        public int $bytesOut = 0,
        public int $uniqueVisitors = 0,
        public float $p95 = 0.0,
    ) {}

    public static function fromSentinel(array $row): self
    {
        return new self(
            bucket: (int) data_get($row, 'bucket', 0),
            s2xx: (int) data_get($row, 's2xx', 0),
            s3xx: (int) data_get($row, 's3xx', 0),
            s4xx: (int) data_get($row, 's4xx', 0),
            s5xx: (int) data_get($row, 's5xx', 0),
            requests: (int) data_get($row, 'requests', 0),
            bytesIn: (int) data_get($row, 'bytes_in', 0),
            bytesOut: (int) data_get($row, 'bytes_out', 0),
            uniqueVisitors: (int) data_get($row, 'unique_visitors', 0),
            p95: (float) data_get($row, 'p95', 0),
        );
    }
}
