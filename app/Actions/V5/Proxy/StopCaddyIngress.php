<?php

namespace App\Actions\V5\Proxy;

use App\Models\PrivateKey;
use App\Models\V5\Server;
use Illuminate\Support\Facades\Process;
use Lorisleiva\Actions\Concerns\AsAction;

class StopCaddyIngress
{
    use AsAction;

    public function handle(Server $server): string
    {
        $server->loadMissing('privateKey');

        if (! $server->privateKey instanceof PrivateKey) {
            return 'No private key is attached to this server.';
        }

        $keyLocation = $this->writeTemporaryPrivateKey($server->privateKey);

        try {
            $result = Process::timeout(60)->run([
                'ssh',
                '-o',
                'BatchMode=yes',
                '-o',
                'LogLevel=ERROR',
                '-o',
                'StrictHostKeyChecking=no',
                '-o',
                'UserKnownHostsFile=/dev/null',
                '-o',
                'ConnectTimeout=10',
                '-o',
                'IdentitiesOnly=yes',
                '-i',
                $keyLocation,
                '-p',
                (string) $server->ssh_port,
                "{$server->ssh_user}@{$server->host}",
                'if command -v podman >/dev/null 2>&1; then runtime="sudo podman"; elif command -v docker >/dev/null 2>&1; then runtime=docker; else echo "Neither podman nor docker is installed" >&2; exit 1; fi; $runtime rm -f coolify-v5-caddy 2>/dev/null || true',
            ]);

            $output = trim($result->output()."\n".$result->errorOutput());

            if ($result->failed()) {
                throw new \RuntimeException('Failed to stop Caddy ingress: '.($output !== '' ? $output : 'No output returned.'));
            }

            $server->update(['caddy_ingress_status' => 'exited']);

            return $output !== '' ? $output : 'Caddy ingress stopped.';
        } finally {
            @unlink($keyLocation);
        }
    }

    private function writeTemporaryPrivateKey(PrivateKey $privateKey): string
    {
        $keyDirectory = storage_path('app/ssh/keys');
        if (! is_dir($keyDirectory)) {
            mkdir($keyDirectory, 0700, true);
        }

        $keyLocation = tempnam($keyDirectory, 'v5_caddy_key_');
        if ($keyLocation === false) {
            throw new \RuntimeException('Could not create a temporary SSH key file.');
        }

        file_put_contents($keyLocation, $privateKey->private_key);
        chmod($keyLocation, 0600);

        return $keyLocation;
    }
}
