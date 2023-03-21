<?php

namespace App\Data;

use App\Enums\ActivityTypes;
use App\Enums\ProcessStatus;
use Spatie\LaravelData\Data;

class RemoteProcessArgs extends Data
{
    public function __construct(
        protected string    $destination,
        protected string    $command,
        protected int       $port = 22,
        protected string    $user = 'root',
        protected string    $type = ActivityTypes::COOLIFY_PROCESS->value,
        protected string    $status = ProcessStatus::HOLDING->value,
    ){}
}
