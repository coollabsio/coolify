<?php

namespace App\Actions\Node;

use App\Models\Node;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * Restores the Sentinel binary, environment file, and unit that InstallSentinel kept as *.previous files.
 * The script is fixed text and never contains user input.
 */
class RollbackSentinel
{
    use AsAction;

    public function handle(Node $node): ?string
    {
        if (! nodesEnabled()) {
            return null;
        }

        return instant_remote_process(
            [InstallSentinel::remoteCommand(self::rollbackScript())],
            $node,
            timeout: 120,
            disableMultiplexing: true,
        );
    }

    public static function rollbackScript(): string
    {
        return <<<'SCRIPT'
set -eu
umask 077

if [ ! -f /usr/local/bin/sentinel.previous ]; then
    echo 'No previous Sentinel binary is available to restore.' >&2
    exit 1
fi

chmod 0755 /usr/local/bin/sentinel.previous
mv -f /usr/local/bin/sentinel.previous /usr/local/bin/sentinel
if [ -f /etc/coolify/sentinel.env.previous ]; then
    chmod 0600 /etc/coolify/sentinel.env.previous
    mv -f /etc/coolify/sentinel.env.previous /etc/coolify/sentinel.env
fi
if [ -f /etc/systemd/system/sentinel.service.previous ]; then
    chmod 0644 /etc/systemd/system/sentinel.service.previous
    mv -f /etc/systemd/system/sentinel.service.previous /etc/systemd/system/sentinel.service
fi
systemctl daemon-reload
systemctl restart sentinel.service
systemctl is-active --quiet sentinel.service
# The discovery DNS runs the same binary, so it must run the restored version too.
systemctl try-restart coolify-discovery-dns.service || true
SCRIPT;
    }
}
