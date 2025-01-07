<?php

namespace App\Livewire\Server;

use App\Actions\Proxy\CheckProxy;
use App\Actions\Proxy\StartProxy;
use App\Models\Server;
use Livewire\Component;

class ValidateAndInstall extends Component
{
    public Server $server;

    public int $number_of_tries = 0;

    public int $max_tries = 3;

    public bool $install = true;

    public $uptime = null;

    public $supported_os_type = null;

    public $docker_installed = null;

    public $docker_compose_installed = null;

    public $docker_version = null;

    public $proxy_started = false;

    public $error = null;

    public bool $ask = false;

    protected $listeners = [
        'init',
        'validateConnection',
        'validateOS',
        'validateDockerEngine',
        'validateDockerVersion',
        'startProxy',
        'refresh' => '$refresh',
    ];

    public function init(int $data = 0)
    {
        $this->uptime = null;
        $this->supported_os_type = null;
        $this->docker_installed = null;
        $this->docker_version = null;
        $this->docker_compose_installed = null;
        $this->proxy_started = null;
        $this->error = null;
        $this->number_of_tries = $data;
        if (! $this->ask) {
            $this->dispatch('validateConnection');
        }
    }

    public function startValidatingAfterAsking()
    {
        $this->ask = false;
        $this->init();
    }

    public function startProxy()
    {
        try {
            $shouldStart = CheckProxy::run($this->server);
            if ($shouldStart) {
                $proxy = StartProxy::run($this->server, false);
                if ($proxy === 'OK') {
                    $this->proxy_started = true;
                } else {
                    throw new \Exception('Proxy could not be started.');
                }
            } else {
                $this->proxy_started = true;
            }
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function validateConnection()
    {
        ['uptime' => $this->uptime, 'error' => $error] = $this->server->validateConnection();
        if (! $this->uptime) {
            $this->error = 'Server is not reachable. Please validate your configuration and connection.<br>Check this <a target="_blank" class="text-black underline dark:text-white" href="https://coolify.io/docs/knowledge-base/server/openssh">documentation</a> for further help. <br><br><div class="text-error">Error: '.$error.'</div>';
            $this->server->update([
                'validation_logs' => $this->error,
            ]);

            return;
        }
        $this->dispatch('validateOS');
    }

    public function validateOS()
    {
        $this->supported_os_type = $this->server->validateOS();
        if (! $this->supported_os_type) {
            $this->error = 'Server OS type is not supported. Please install Docker manually before continuing: <a target="_blank" class="underline" href="https://docs.docker.com/engine/install/#server">documentation</a>.';
            $this->server->update([
                'validation_logs' => $this->error,
            ]);

            return;
        }
        $this->dispatch('validateDockerEngine');
    }

    public function validateDockerEngine()
    {
        $this->docker_installed = $this->server->validateDockerEngine();
        $this->docker_compose_installed = $this->server->validateDockerCompose();
        if (! $this->docker_installed || ! $this->docker_compose_installed) {
            if ($this->install) {
                if ($this->number_of_tries == $this->max_tries) {
                    $this->error = 'Docker Engine could not be installed. Please install Docker manually before continuing: <a target="_blank" class="underline" href="https://docs.docker.com/engine/install/#server">documentation</a>.';
                    $this->server->update([
                        'validation_logs' => $this->error,
                    ]);

                    return;
                } else {
                    if ($this->number_of_tries <= $this->max_tries) {
                        $activity = $this->server->installDocker();
                        $this->number_of_tries++;
                        $this->dispatch('newActivityMonitor', $activity->id, 'init', $this->number_of_tries);
                    }

                    return;
                }
            } else {
                $this->error = 'Docker Engine is not installed. Please install Docker manually before continuing: <a target="_blank" class="underline" href="https://docs.docker.com/engine/install/#server">documentation</a>.';
                $this->server->update([
                    'validation_logs' => $this->error,
                ]);

                return;
            }
        }
        $this->dispatch('validateDockerVersion');
    }

    public function validateDockerVersion()
    {
        if ($this->server->isSwarm()) {
            $swarmInstalled = $this->server->validateDockerSwarm();
            if ($swarmInstalled) {
                $this->dispatch('success', 'Docker Swarm is initiated.');
            }
        } else {
            $this->docker_version = $this->server->validateDockerEngineVersion();
            if ($this->docker_version) {
                $this->dispatch('refreshServerShow');
                $this->dispatch('refreshBoardingIndex');
                $this->dispatch('success', 'Server validated.');
            } else {
                $requiredDockerVersion = str(config('constants.docker.minimum_required_version'))->before('.');
                $this->error = 'Minimum Docker Engine version '.$requiredDockerVersion.' is not instaled. Please install Docker manually before continuing: <a target="_blank" class="underline" href="https://docs.docker.com/engine/install/#server">documentation</a>.';
                $this->server->update([
                    'validation_logs' => $this->error,
                ]);

                return;
            }
        }

        if ($this->server->isBuildServer()) {
            return;
        }
        $this->dispatch('startProxy');
    }

    public function render()
    {
        return view('livewire.server.validate-and-install');
    }
}
