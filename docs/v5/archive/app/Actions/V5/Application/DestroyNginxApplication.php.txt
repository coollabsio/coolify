<?php

namespace App\Actions\V5\Application;

use App\Models\PrivateKey;
use App\Models\V5\Application;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Process;
use Lorisleiva\Actions\Concerns\AsAction;

class DestroyNginxApplication
{
    use AsAction;

    public function handle(Application $application): ?string
    {
        $application->loadMissing('server.privateKey');
        $server = $application->server;

        if ($server === null || ! $server->privateKey instanceof PrivateKey) {
            return null;
        }

        $keyLocation = $this->writeTemporaryPrivateKey($server->privateKey);

        try {
            $result = Process::timeout(120)->run([
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
                $this->remoteCommand($application),
            ]);

            if (! $result->successful()) {
                return $this->processOutput($result);
            }

            return null;
        } catch (\Throwable $e) {
            return $e->getMessage();
        } finally {
            @unlink($keyLocation);
        }
    }

    private function remoteCommand(Application $application): string
    {
        $containerName = escapeshellarg($application->container_name);

        return implode(PHP_EOL, [
            'set -e',
            'if [ "$(id -u)" = "0" ]; then podman=podman; else podman="sudo -n podman"; fi',
            "\$podman rm -f {$containerName} >/dev/null 2>&1 || true",
        ]);
    }

    private function processOutput(ProcessResult $result): string
    {
        $output = trim($result->output()."\n".$result->errorOutput());

        return $output !== '' ? $output : 'Could not delete nginx container.';
    }

    private function writeTemporaryPrivateKey(PrivateKey $privateKey): string
    {
        $keyDirectory = storage_path('app/ssh/keys');
        if (! is_dir($keyDirectory)) {
            mkdir($keyDirectory, 0700, true);
        }

        $keyLocation = tempnam($keyDirectory, 'v5_nginx_destroy_key_');
        if ($keyLocation === false) {
            throw new \RuntimeException('Could not create a temporary SSH key file.');
        }

        file_put_contents($keyLocation, $privateKey->private_key);
        chmod($keyLocation, 0600);

        return $keyLocation;
    }
}
