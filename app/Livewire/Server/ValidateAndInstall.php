<?php

namespace App\Livewire\Server;

use App\Models\Server;
use Livewire\Component;

class ValidateAndInstall extends Component
{
    public Server $server;
    public int $number_of_tries = 0;
    public int $max_tries = 1;
    public bool $install = true;
    public $uptime = null;
    public $supported_os_type = null;
    public $docker_installed = null;
    public $docker_version = null;
    public $error = null;

    protected $listeners = ['validateServer' => 'init', 'validateDockerEngine', 'validateServerNow' => 'validateServer'];

    public function init(bool $install = true)
    {
        $this->install = $install;
        $this->uptime = null;
        $this->supported_os_type = null;
        $this->docker_installed = null;
        $this->docker_version = null;
        $this->error = null;
        $this->number_of_tries = 0;
        $this->dispatch('validateServerNow');
    }

    public function validateServer()
    {
        try {
            $this->validateConnection();
            $this->validateOS();
            $this->validateDockerEngine();

            if ($this->server->isSwarm()) {
                $swarmInstalled = $this->server->validateDockerSwarm();
                if ($swarmInstalled) {
                    $this->dispatch('success', 'Docker Swarm is initiated.');
                }
            }
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }
    public function validateConnection()
    {
        $this->uptime = $this->server->validateConnection();
        if (!$this->uptime) {
            $this->error = 'Server is not reachable. Please validate your configuration and connection.<br><br>Check this <a target="_blank" class="underline" href="https://coolify.io/docs/server/openssh">documentation</a> for further help.';
            return;
        }
    }
    public function validateOS()
    {
        $this->supported_os_type = $this->server->validateOS();
        if (!$this->supported_os_type) {
            $this->error = 'Server OS type is not supported. Please install Docker manually before continuing: <a target="_blank" class="underline" href="https://docs.docker.com/engine/install/#server">documentation</a>.';
            return;
        }
    }
    public function validateDockerEngine()
    {
        $this->docker_installed = $this->server->validateDockerEngine();
        if (!$this->docker_installed) {
            if ($this->install) {
                ray($this->number_of_tries, $this->max_tries);
                if ($this->number_of_tries == $this->max_tries) {
                    $this->error = 'Docker Engine could not be installed. Please install Docker manually before continuing: <a target="_blank" class="underline" href="https://docs.docker.com/engine/install/#server">documentation</a>.';
                    return;
                } else {
                    $activity = $this->server->installDocker();
                    $this->number_of_tries++;
                    $this->dispatch('newActivityMonitor', $activity->id, 'validateDockerEngine');
                    return;
                }
            } else {
                $this->error = 'Docker Engine is not installed. Please install Docker manually before continuing: <a target="_blank" class="underline" href="https://docs.docker.com/engine/install/#server">documentation</a>.';
                return;
            }
        } else {
            $this->validateDockerVersion();
        }
    }
    public function validateDockerVersion()
    {
        $this->docker_version = $this->server->validateDockerEngineVersion();
        if ($this->docker_version) {
            $this->dispatch('serverInstalled');
            $this->dispatch('success', 'Server validated successfully.');
        } else {
            $this->error = 'Docker Engine version is not 22+. Please install Docker manually before continuing: <a target="_blank" class="underline" href="https://docs.docker.com/engine/install/#server">documentation</a>.';
            return;
        }
    }
    public function render()
    {
        return view('livewire.server.validate-and-install');
    }
}
