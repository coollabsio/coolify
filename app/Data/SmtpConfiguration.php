<?php

namespace App\Data;

use Spatie\LaravelData\Data;

class SmtpConfiguration extends Data
{
    public function __construct(
        public bool $smtp_enabled = false,
        public string $smtp_host,
        public int $smtp_port,
        public ?string $smtp_encryption,
        public ?string $smtp_username,
        public ?string $smtp_password,
        public ?int $smtp_timeout,
        public string $smtp_from_address,
        public string $smtp_from_name,
        public ?string $smtp_recipients,
        public ?string $smtp_test_recipients,
    ) {
    }
}
