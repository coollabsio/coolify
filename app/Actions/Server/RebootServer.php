<?php

namespace App\Actions\Server;

use App\Jobs\ServerPatchCheckJob;
use App\Models\Server;
use Lorisleiva\Actions\Concerns\AsAction;

class RebootServer
{
    use AsAction;

    /**
     * Starts the reboot a few seconds later, so the SSH command returns before the connection drops.
     * systemd-run runs the reboot outside of the SSH session; other systems use a detached `reboot`.
     */
    public const COMMAND = <<<'SH'
sh -c 'if command -v systemd-run >/dev/null 2>&1; then systemd-run --on-active=5 --timer-property=AccuracySec=1s systemctl reboot >/dev/null 2>&1; else nohup sh -c "sleep 5; reboot" >/dev/null 2>&1 </dev/null & fi; echo REBOOT_SCHEDULED'
SH;

    public function handle(Server $server): void
    {
        $server->assertNotTransferredAway();
        if (! $server->isFunctional()) {
            throw new \RuntimeException('Server is not reachable or not ready.');
        }

        $output = instant_remote_process([self::COMMAND], $server);
        if (! str_contains((string) $output, 'REBOOT_SCHEDULED')) {
            throw new \RuntimeException('The reboot could not be started.');
        }

        // Check again when the server is back, so the "Reboot required" state is cleared.
        ServerPatchCheckJob::dispatch($server, true)->delay(now()->addMinutes(5));
    }
}
