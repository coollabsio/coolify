<?php

namespace App\Actions\Server;

use App\Models\Server;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * Removes the Sentinel host service. Safe to run when nothing is installed and on hosts without systemd.
 * The data directory stays, so the metrics history survives a switch back to the container.
 */
class RemoveHostSentinel
{
    use AsAction;

    public function handle(Server $server): void
    {
        instant_remote_process([InstallHostSentinel::remoteCommand(self::removalScript())], $server, throwError: false, timeout: 120);
    }

    public static function removalScript(): string
    {
        return <<<'SCRIPT'
set -u
if command -v systemctl >/dev/null 2>&1; then
    systemctl disable --now sentinel.service >/dev/null 2>&1 || true
fi
rm -f /etc/systemd/system/sentinel.service /etc/systemd/system/sentinel.service.previous
rm -f /usr/local/bin/sentinel /usr/local/bin/sentinel.previous
rm -f /etc/coolify/sentinel.env /etc/coolify/sentinel.env.previous
if command -v systemctl >/dev/null 2>&1; then
    systemctl daemon-reload >/dev/null 2>&1 || true
    systemctl reset-failed sentinel.service >/dev/null 2>&1 || true
fi
SCRIPT;
    }
}
