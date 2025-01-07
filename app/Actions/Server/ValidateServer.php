<?php

namespace App\Actions\Server;

use App\Models\Server;
use Lorisleiva\Actions\Concerns\AsAction;

class ValidateServer
{
    use AsAction;

    public string $jobQueue = 'high';

    public ?string $uptime = null;

    public ?string $error = null;

    public ?string $supported_os_type = null;

    public ?string $docker_installed = null;

    public ?string $docker_compose_installed = null;

    public ?string $docker_version = null;

    public function handle(Server $server)
    {
        $server->update([
            'validation_logs' => null,
        ]);
        ['uptime' => $this->uptime, 'error' => $error] = $server->validateConnection();
        if (! $this->uptime) {
            $this->error = 'Server is not reachable. Please validate your configuration and connection.<br>Check this <a target="_blank" class="text-black underline dark:text-white" href="https://coolify.io/docs/knowledge-base/server/openssh">documentation</a> for further help. <br><br><div class="text-error">Error: '.$error.'</div>';
            $server->update([
                'validation_logs' => $this->error,
            ]);
            throw new \Exception($this->error);
        }
        $this->supported_os_type = $server->validateOS();
        if (! $this->supported_os_type) {
            $this->error = 'Server OS type is not supported. Please install Docker manually before continuing: <a target="_blank" class="text-black underline dark:text-white" href="https://docs.docker.com/engine/install/#server">documentation</a>.';
            $server->update([
                'validation_logs' => $this->error,
            ]);
            throw new \Exception($this->error);
        }

        $this->docker_installed = $server->validateDockerEngine();
        $this->docker_compose_installed = $server->validateDockerCompose();
        if (! $this->docker_installed || ! $this->docker_compose_installed) {
            $this->error = 'Docker Engine is not installed. Please install Docker manually before continuing: <a target="_blank" class="text-black underline dark:text-white" href="https://docs.docker.com/engine/install/#server">documentation</a>.';
            $server->update([
                'validation_logs' => $this->error,
            ]);
            throw new \Exception($this->error);
        }
        $this->docker_version = $server->validateDockerEngineVersion();

        if ($this->docker_version) {
            return 'OK';
        } else {
            $this->error = 'Docker Engine is not installed. Please install Docker manually before continuing: <a target="_blank" class="text-black underline dark:text-white" href="https://docs.docker.com/engine/install/#server">documentation</a>.';
            $server->update([
                'validation_logs' => $this->error,
            ]);
            throw new \Exception($this->error);
        }
    }
}
