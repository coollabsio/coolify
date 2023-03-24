<?php

namespace App\Data;

use App\Enums\ActivityTypes;
use App\Enums\ProcessStatus;
use Spatie\LaravelData\Data;

class RemoteProcessArgs extends Data
{
    public function __construct(
        public string    $destination,
        public string    $private_key_location,
        public string    $command,
        public int       $port,
        public string    $user,
        public string    $type = ActivityTypes::COOLIFY_PROCESS->value,
        public string    $status = ProcessStatus::HOLDING->value,
    ){}
}
