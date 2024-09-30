<?php

namespace App\Actions\Server;

use App\Enums\ActivityTypes;
use App\Models\Server;
use Lorisleiva\Actions\Concerns\AsAction;

class RunCommand
{
    use AsAction;

    public function handle(Server $server, $command)
    {
        $activity = remote_process(command: [$command], server: $server, ignore_errors: true, type: ActivityTypes::COMMAND->value);

        return $activity;
    }
}
