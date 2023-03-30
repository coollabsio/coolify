<?php

namespace App\Data;

use App\Enums\ActivityTypes;
use App\Enums\ProcessStatus;
use Illuminate\Database\Eloquent\Model;
use Spatie\LaravelData\Data;

class RemoteProcessArgs extends Data
{
    public function __construct(
        public Model|null       $model,
        public string           $server_ip,
        public string           $private_key_location,
        public string|null      $deployment_uuid,
        public string           $command,
        public int              $port,
        public string           $user,
        public string           $type = ActivityTypes::REMOTE_PROCESS->value,
        public string           $status = ProcessStatus::HOLDING->value,
    ) {
    }
}
