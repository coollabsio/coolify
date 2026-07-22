<?php

namespace App\Actions\V5\Server;

use App\Models\PrivateKey;
use App\Models\V5\Server;
use App\Services\Flux\AgentTokenIssuer;
use Illuminate\Support\Facades\Process;
use Lorisleiva\Actions\Concerns\AsAction;

class RemoveBootstrapMarker
{
    use AsAction;

    /**
     * Best-effort removal of the on-host bootstrap identity (marker, host JWT and
     * Flux drop-in) so a re-added server can never silently adopt stale state.
     *
     * The host token jti is revoked first (a local DB write plus a best-effort
     * push to the flux revocation store) so a captured or pre-copied token is
     * recorded revoked even when the host is unreachable — see
     * AgentTokenIssuer::revoke.
     */
    public function handle(Server $server): bool
    {
        app(AgentTokenIssuer::class)->revoke($server);

        $server->loadMissing('privateKey');

        if (! $server->privateKey instanceof PrivateKey) {
            return false;
        }

        $keyDirectory = storage_path('app/ssh/keys');
        if (! is_dir($keyDirectory)) {
            mkdir($keyDirectory, 0700, true);
        }

        $keyLocation = tempnam($keyDirectory, 'v5_ssh_key_');
        if ($keyLocation === false) {
            return false;
        }

        file_put_contents($keyLocation, $server->privateKey->private_key);
        chmod($keyLocation, 0600);

        $script = implode("\n", [
            "SUDO=''",
            'if [ "$(id -u)" != "0" ]; then SUDO=\'sudo\'; fi',
            '$SUDO rm -f /etc/coolify/v5-node.json /etc/coolify/host-jwt /etc/systemd/system/coold.service.d/10-flux.conf',
        ]);

        try {
            $result = Process::timeout(15)->run([
                'ssh',
                '-o', 'BatchMode=yes',
                '-o', 'LogLevel=ERROR',
                '-o', 'StrictHostKeyChecking=no',
                '-o', 'UserKnownHostsFile=/dev/null',
                '-o', 'ConnectTimeout=10',
                '-o', 'IdentitiesOnly=yes',
                '-i', $keyLocation,
                '-p', (string) $server->ssh_port,
                "{$server->ssh_user}@{$server->host}",
                $script,
            ]);

            return $result->successful();
        } catch (\Throwable) {
            return false;
        } finally {
            @unlink($keyLocation);
        }
    }
}
