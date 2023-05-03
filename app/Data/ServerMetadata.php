<?php

namespace App\Data;

use App\Enums\ProxyTypes;
use Spatie\LaravelData\Data;

class ServerMetadata extends Data
{
    public function __construct(
        public ?ProxyTypes $proxy,
    ) {}
}
