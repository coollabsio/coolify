<?php

namespace App\Actions\Server;

use App\Models\Server;
use Lorisleiva\Actions\Concerns\AsAction;

class ValidatePrerequisites
{
    use AsAction;

    public string $jobQueue = 'high';

    /**
     * Validate that required commands are available on the server.
     *
     * @return array{success: bool, missing: array<string>, found: array<string>}
     */
    public function handle(Server $server): array
    {
        $requiredCommands = ['git', 'curl', 'jq'];
        $missing = [];
        $found = [];

        foreach ($requiredCommands as $cmd) {
            $result = instant_remote_process(["command -v {$cmd}"], $server, false);
            if (! $result) {
                $missing[] = $cmd;
            } else {
                $found[] = $cmd;
            }
        }

        return [
            'success' => empty($missing),
            'missing' => $missing,
            'found' => $found,
        ];
    }
}
