<?php

namespace App\Actions\Node;

use App\Models\Node;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * Restores the Sentinel binary that InstallSentinel kept at /usr/local/bin/sentinel.previous.
 * The script is fixed text and never contains user input.
 */
class RollbackSentinel
{
    use AsAction;

    public function handle(Node $node): ?string
    {
        if (! isDev() || ! config('constants.sentinel.host_enabled', false)) {
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
systemctl restart sentinel.service
systemctl is-active --quiet sentinel.service
SCRIPT;
    }
}
