<?php

namespace App\Support;

final readonly class ServiceEndpoint
{
    public function __construct(
        public string $key,
        public string $label,
        public string $envKey,
        public int $port,
        public string $urlPrefix,
        public bool $forceHttps,
    ) {}
}
