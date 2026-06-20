<?php

namespace App\Actions\V5\Proxy;

use App\Models\PrivateKey;
use App\Models\V5\Server;
use Illuminate\Support\Facades\Process;
use Lorisleiva\Actions\Concerns\AsAction;

class StartCaddyIngress
{
    use AsAction;

    public function handle(Server $server): string
    {
        $server->loadMissing('privateKey');

        if (! $server->isIngress()) {
            return 'Server is not an ingress server.';
        }

        if (! $server->privateKey instanceof PrivateKey) {
            return 'No private key is attached to this server.';
        }

        $keyLocation = $this->writeTemporaryPrivateKey($server->privateKey);

        try {
            $commands = GenerateCaddyIngressConfiguration::run()['commands'];
            $result = Process::timeout(180)->run([
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
                implode("\n", $commands),
            ]);

            $output = trim($result->output()."\n".$result->errorOutput());

            if ($result->failed()) {
                $server->update(['caddy_ingress_status' => 'failed']);

                throw new \RuntimeException('Failed to start Caddy ingress: '.($output !== '' ? $output : 'No output returned.'));
            }

            $server->update(['caddy_ingress_status' => 'running']);

            return $output !== '' ? $output : 'Caddy ingress started.';
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
