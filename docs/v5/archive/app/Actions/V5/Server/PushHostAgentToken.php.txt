<?php

namespace App\Actions\V5\Server;

use App\Models\PrivateKey;
use App\Models\V5\Server;
use Illuminate\Support\Facades\Process;
use Lorisleiva\Actions\Concerns\AsAction;

class PushHostAgentToken
{
    use AsAction;

    /**
     * Best-effort SSH push of a freshly minted host JWT to the on-host jwt path.
     *
     * coold re-reads the JWT file on every reconnect and flux drops the stream
     * at the token's exp, so overwriting the file in place is enough for the
     * next reconnect to pick up the new token — coold is intentionally NOT
     * restarted here (a restart would force an unnecessary disconnect of a
     * stream that is still valid on the current token).
     *
     * Mirrors V5BootstrapServerJob::enrollCooldIntoFlux for the write mechanics
     * (printf %s <token> | sudo tee <path>; chmod 600) and RemoveBootstrapMarker
     * for the SSH/temp-key mechanics. Returns whether the write succeeded;
     * every failure path (missing key, SSH error, exception) resolves to false
     * and always cleans up the temporary key file.
     */
    public function handle(Server $server, string $token): bool
    {
        $server->loadMissing('privateKey');

        if (! $server->privateKey instanceof PrivateKey) {
            return false;
        }

        $jwtPath = trim((string) config('coold.flux_host_jwt_path', '/etc/coolify/host-jwt'));

        if ($jwtPath === '') {
            $jwtPath = '/etc/coolify/host-jwt';
        }

        $jwtPath = str_replace(["\r", "\n"], '', $jwtPath);
        $token = str_replace(["\r", "\n"], '', $token);

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

        $tokenArgument = escapeshellarg($token);
        $jwtPathArgument = $this->shellPathArg($jwtPath);
        $script = <<<SH
set -e
SUDO=''
if [ "\$(id -u)" != "0" ]; then SUDO='sudo'; fi
\$SUDO mkdir -p /etc/coolify
printf %s {$tokenArgument} | \$SUDO tee {$jwtPathArgument} >/dev/null
\$SUDO chmod 600 {$jwtPathArgument}
SH;

        try {
            $result = Process::timeout(30)->run([
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

    private function shellPathArg(string $value): string
    {
        if (preg_match('/^[A-Za-z0-9_\/:.,@%+=-]+$/', $value) === 1) {
            return $value;
        }

        return escapeshellarg($value);
    }
}
