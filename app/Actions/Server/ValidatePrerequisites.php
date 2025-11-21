<?php

namespace App\Actions\Server;

use App\Models\Server;
use Lorisleiva\Actions\Concerns\AsAction;

class ValidatePrerequisites
{
    use AsAction;

    public string $jobQueue = 'high';

    public function handle(Server $server): bool
    {
        $requiredCommands = ['git', 'curl', 'jq'];

        foreach ($requiredCommands as $cmd) {
            $found = instant_remote_process(["command -v {$cmd}"], $server, false);
            if (! $found) {
                return false;
            }
        }

        return true;
    }
}
