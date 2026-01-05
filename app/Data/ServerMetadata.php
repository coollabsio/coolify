<?php

namespace App\Data;

use App\Enums\ProxyStatus;
use App\Enums\ProxyTypes;
use Spatie\LaravelData\Data;

class ServerMetadata extends Data
{
    public function __construct(
        public ?ProxyTypes $type,
        public ?ProxyStatus $status,
        public ?string $last_saved_settings = null,
        public ?string $last_applied_settings = null
    ) {}
}
