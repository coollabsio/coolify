<?php

namespace App\Services\Dns;

/**
 * A single parsed DNS address record (A or AAAA).
 */
final class DnsRecord
{
    public function __construct(
        private readonly string $type,
        private readonly string $data,
    ) {}

    public function getType(): string
    {
        return $this->type;
    }

    public function getData(): string
    {
        return $this->data;
    }
}
