<?php

namespace App\Actions\V5\Application;

use App\Models\PrivateKey;
use App\Models\V5\Application;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Process;
use Lorisleiva\Actions\Concerns\AsAction;

class DeployNginxApplication
{
    use AsAction;

    public function handle(Application $application): Application
    {
        $application->loadMissing('server.privateKey');
        $server = $application->server;

        if ($server === null) {
            return $this->markFailed($application, 'No server is attached to this application.');
        }

        if (! $server->privateKey instanceof PrivateKey) {
            return $this->markFailed($application, 'No private key is attached to this server.');
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
                return $this->markFailed($application, $this->processOutput($result));
            }

            $containerId = trim($result->output());

            $application->update([
                'status' => 'running',
                'status_message' => 'Container started.',
                'runtime_container_id' => $containerId !== '' ? $containerId : null,
            ]);

            return $application->refresh()->load('server');
        } catch (\Throwable $e) {
            return $this->markFailed($application, $e->getMessage());
        } finally {
            @unlink($keyLocation);
        }
    }

    private function remoteCommand(Application $application): string
    {
        $image = escapeshellarg($application->image);
        $containerName = escapeshellarg($application->container_name);
        $network = escapeshellarg($this->meshNetwork($application));

        return implode(PHP_EOL, [
            'set -e',
            'if [ "$(id -u)" = "0" ]; then podman=podman; else podman="sudo -n podman"; fi',
            'if ! $podman --version >/dev/null 2>&1; then echo "Rootful Podman is required for v5 mesh applications." >&2; exit 1; fi',
            "if ! \$podman network exists {$network}; then echo 'Mesh network {$network} does not exist. Bootstrap this server into the v5 mesh first.' >&2; exit 1; fi",
            "container_id=\$(\$podman run -d --replace --name {$containerName} --network {$network} --network-alias {$containerName} {$image})",
            'sleep 1',
            "is_running=$(\$podman inspect -f '{{.State.Running}}' {$containerName} 2>/dev/null || printf false)",
            'if [ "$is_running" != "true" ]; then',
            "  echo 'Container did not stay running.' >&2",
            "  \$podman ps -a --filter name={$containerName} >&2 || true",
            '  exit 1',
            'fi',
            'printf %s "$container_id"',
        ]);
    }

    private function meshNetwork(Application $application): string
    {
        $namespace = $application->mesh_namespace ?: 'default';

        return "coolify-{$namespace}-mesh";
    }

    private function processOutput(ProcessResult $result): string
    {
        $output = trim($result->output()."\n".$result->errorOutput());

        return $output !== '' ? $output : 'Could not start nginx container.';
    }

    private function markFailed(Application $application, string $message): Application
    {
        $application->update([
            'status' => 'failed',
            'status_message' => str($message)->limit(10000)->toString(),
        ]);

        return $application->refresh()->load('server');
    }

    private function writeTemporaryPrivateKey(PrivateKey $privateKey): string
    {
        $keyDirectory = storage_path('app/ssh/keys');
        if (! is_dir($keyDirectory)) {
            mkdir($keyDirectory, 0700, true);
        }

        $keyLocation = tempnam($keyDirectory, 'v5_nginx_key_');
        if ($keyLocation === false) {
            throw new \RuntimeException('Could not create a temporary SSH key file.');
        }

        file_put_contents($keyLocation, $privateKey->private_key);
        chmod($keyLocation, 0600);

        return $keyLocation;
    }
}
