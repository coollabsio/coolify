<?php

namespace App\Livewire\Server;

use App\Actions\Proxy\CheckProxy;
use App\Actions\Proxy\StartProxy;
use App\Actions\Server\InstallCoolifyDependencies;
use App\Actions\Server\ValidateServer;
use App\Models\Server;
use Livewire\Component;

class ValidateAndInstall extends Component
{
    public Server $server;

    public ?string $error = null;

    public ?string $supported_os_type = null;

    public ?string $os_display_name = null;

    public bool $docker_engine_installed = false;

    public ?string $docker_engine_version = null;

    public bool $docker_compose_installed = false;

    public ?string $docker_compose_version = null;

    public bool $docker_version_valid = false;

    public bool $proxy_started = false;

    public bool $install = true;

    // Step tracking
    public string $currentStep = '';

    public array $steps = [
        'connection' => [
            'label' => 'Connection Check',
            'completed' => false,
        ],
        'os' => [
            'label' => 'OS Validation',
            'completed' => false,
        ],
        'docker' => [
            'label' => 'Docker Engine',
            'completed' => false,
        ],
        'compose' => [
            'label' => 'Docker Compose',
            'completed' => false,
        ],
        'dependencies' => [
            'label' => 'Dependencies',
            'completed' => false,
        ],
        'proxy' => [
            'label' => 'Proxy',
            'completed' => false,
        ],
    ];

    protected $listeners = [
        'validateConnection',
        'validateOS',
        'validateDockerEngine',
        'validateDockerCompose',
        'validateDockerVersion',
        'startProxy',
        'refresh' => '$refresh',
    ];

    public function mount()
    {
        $this->currentStep = '';
        $this->error = null;
        $this->supported_os_type = null;
        $this->docker_engine_installed = false;
        $this->docker_compose_installed = false;
        $this->docker_version_valid = false;
        $this->proxy_started = false;
        $this->docker_compose_version = null;
        $this->docker_engine_version = null;

        $this->currentStep = 'connection';
        $this->dispatch('validateConnection');
    }

    public function validateConnection()
    {
        ['uptime' => $uptime, 'error' => $error] = (new ValidateServer)->validateConnection($this->server);

        if (! $uptime) {
            $this->error = $error;

            return;
        }

        $this->steps['connection']['completed'] = true;
        $this->currentStep = 'os';
        $this->dispatch('validateOS');
    }

    public function validateOS()
    {
        $result = (new ValidateServer)->validateOS($this->server);

        if (! $result['supported']) {
            $this->error = $result['error'];

            return;
        }

        $this->supported_os_type = $result['os_type'];
        $this->os_display_name = $result['display_name'];
        $this->steps['os']['completed'] = true;
        $this->currentStep = 'docker';
        $this->dispatch('validateDockerEngine');
    }

    public function validateDockerEngine()
    {
        $result = (new ValidateServer)->validateDockerEngine($this->server);
        $this->docker_engine_installed = $result['installed'];
        $this->docker_engine_version = $result['version'] ?? null;

        if (! $this->docker_engine_installed) {
            $this->steps['docker']['completed'] = true; // Mark as completed even if not installed
            if ($this->install) {
                $this->currentStep = 'dependencies';
                $this->installDependencies();

                return;
            }
            $this->error = $result['error'];

            return;
        }

        $this->steps['docker']['completed'] = true;
        $this->currentStep = 'compose';
        $this->dispatch('validateDockerCompose');
    }

    public function validateDockerCompose()
    {
        $result = (new ValidateServer)->validateDockerCompose($this->server);
        $this->docker_compose_installed = $result['installed'];
        $this->docker_compose_version = $result['version'] ?? null;

        if (! $this->docker_compose_installed) {
            $this->steps['compose']['completed'] = true;
            if ($this->install) {
                $this->currentStep = 'dependencies';
                $this->installDependencies();

                return;
            }
            $this->error = __('server.docker_compose_not_installed');

            return;
        }

        $this->steps['compose']['completed'] = true;
        $this->dispatch('validateDockerVersion');
    }

    protected function installDependencies()
    {
        try {
            InstallCoolifyDependencies::run($this->server, $this->supported_os_type);
            $result = (new ValidateServer)->validateDockerEngine($this->server);
            if (! $result['installed']) {
                $this->error = __('server.docker_install_failed');
                $this->server->update(['validation_logs' => $this->error]);

                return;
            }
            $this->docker_engine_installed = true;
            $this->docker_engine_version = $result['version'] ?? null;
            $this->steps['dependencies']['completed'] = true;
            $this->currentStep = 'compose';
            $this->dispatch('validateDockerCompose');
        } catch (\Exception $e) {
            $this->error = __('server.dependency_install_failed', ['error' => $e->getMessage()]);
            $this->server->update(['validation_logs' => $this->error]);
        }
    }

    public function validateDockerVersion()
    {
        if ($this->server->isSwarm()) {
            $this->validateSwarmSetup();
        } else {
            $this->validateStandardSetup();
        }
    }

    protected function validateSwarmSetup()
    {
        try {
            $swarmInstalled = (new ValidateServer)->validateDockerSwarm($this->server);
            if ($swarmInstalled) {
                $this->currentStep = 'proxy';
                $this->dispatch('startProxy');
            }
        } catch (\Exception $e) {
            $this->error = $e->getMessage();
        }
    }

    protected function validateStandardSetup()
    {
        $result = (new ValidateServer)->validateDockerEngineVersion($this->server);
        $this->docker_version_valid = $result['valid'];

        if (! $this->docker_version_valid) {
            $requiredDockerVersion = str(config('constants.docker.minimum_required_version'))->before('.');
            $this->error = __('server.docker_version_not_supported', ['version' => $requiredDockerVersion]);
            $this->server->update(['validation_logs' => $this->error]);

            return;
        }

        if (! $this->server->isBuildServer()) {
            $this->currentStep = 'proxy';
            $this->dispatch('startProxy');
        }
    }

    public function startProxy()
    {
        try {
            $shouldStart = CheckProxy::run($this->server);
            if ($shouldStart) {
                $proxy = StartProxy::run($this->server, false);
                if ($proxy === 'OK') {
                    $this->proxy_started = true;
                    $this->steps['proxy']['completed'] = true;
                } else {
                    throw new \Exception(__('server.proxy_start_failed'));
                }
            } else {
                $this->proxy_started = true;
                $this->steps['proxy']['completed'] = true;
            }
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function render()
    {
        return view('livewire.server.validate-and-install');
    }
}
