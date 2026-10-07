<?php

namespace App\Data\Traffic;

use Spatie\LaravelData\Data;

class TrafficOverviewData extends Data
{
    public function __construct(
        public int $requests,
        public int $bytesIn,
        public int $bytesOut,
        public int $s2xx,
        public int $s3xx,
        public int $s4xx,
        public int $s5xx,
        public float $latencyP50,
        public float $latencyP95,
        public float $latencyP99,
        public int $uniqueVisitors,
    ) {}

    public static function fromSentinel(array $json): self
    {
        return new self(
            requests: (int) data_get($json, 'requests', 0),
            bytesIn: (int) data_get($json, 'bytes_in', 0),
            bytesOut: (int) data_get($json, 'bytes_out', 0),
            s2xx: (int) data_get($json, 'status.s2xx', 0),
            s3xx: (int) data_get($json, 'status.s3xx', 0),
            s4xx: (int) data_get($json, 'status.s4xx', 0),
            s5xx: (int) data_get($json, 'status.s5xx', 0),
            latencyP50: (float) data_get($json, 'latency.p50', 0.0),
            latencyP95: (float) data_get($json, 'latency.p95', 0.0),
            latencyP99: (float) data_get($json, 'latency.p99', 0.0),
            uniqueVisitors: (int) data_get($json, 'unique_visitors', 0),
        );
    }

    public static function zero(): self
    {
        return new self(0, 0, 0, 0, 0, 0, 0, 0.0, 0.0, 0.0, 0);
    }
}
