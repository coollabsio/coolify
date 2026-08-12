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
    ) {}

    public static function fromSentinel(array $row): self
    {
        return new self(
            bucket: (int) data_get($row, 'bucket', 0),
            s2xx: (int) data_get($row, 's2xx', 0),
            s3xx: (int) data_get($row, 's3xx', 0),
            s4xx: (int) data_get($row, 's4xx', 0),
            s5xx: (int) data_get($row, 's5xx', 0),
        );
    }
}
