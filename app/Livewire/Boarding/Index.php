<?php

namespace App\Livewire\Boarding;

use App\Enums\ProxyTypes;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Server;
use App\Models\Team;
use App\Services\ConfigurationRepository;
use Illuminate\Support\Collection;
use Livewire\Component;
use Visus\Cuid2\Cuid2;

class Index extends Component
{
    protected $listeners = [
        'refreshBoardingIndex' => 'validateServer',
        'prerequisitesInstalled' => 'handlePrerequisitesInstalled',
    ];

    #[\Livewire\Attributes\Url(as: 'step', history: true)]
    public string $currentState = 'welcome';

    #[\Livewire\Attributes\Url(keep: true)]
    public ?string $selectedServerType = null;

    public ?Collection $privateKeys = null;

    #[\Livewire\Attributes\Url(keep: true)]
    public ?int $selectedExistingPrivateKey = null;

    #[\Livewire\Attributes\Url(keep: true)]
    public ?string $privateKeyType = null;

    public ?string $privateKey = null;

    public ?string $publicKey = null;

    public ?string $privateKeyName = null;

    public ?string $privateKeyDescription = null;

    public ?PrivateKey $createdPrivateKey = null;

    public ?Collection $servers = null;

    #[\Livewire\Attributes\Url(keep: true)]
    public ?int $selectedExistingServer = null;

    public ?string $remoteServerName = null;

    public ?string $remoteServerDescription = null;

    public ?string $remoteServerHost = null;

    public ?int $remoteServerPort = 22;

    public ?string $remoteServerUser = 'root';

    public bool $isSwarmManager = false;

    public bool $isCloudflareTunnel = false;

    public ?Server $createdServer = null;

    public Collection $projects;

    #[\Livewire\Attributes\Url(keep: true)]
    public ?int $selectedProject = null;

    public ?Project $createdProject = null;

    public bool $dockerInstallationStarted = false;

    public string $serverPublicKey;

    public bool $serverReachable = true;

    public ?string $minDockerVersion = null;

    public int $prerequisiteInstallAttempts = 0;

    public int $maxPrerequisiteInstallAttempts = 3;

    public function mount()
    {
        if (auth()->user()?->isMember() && auth()->user()->currentTeam()->show_boarding === true) {
            return redirect()->route('dashboard');
        }

        $this->minDockerVersion = str(config('constants.docker.minimum_required_version'))->before('.');
        $this->privateKeyName = generate_random_name();
        $this->remoteServerName = generate_random_name();

        // Initialize collections to avoid null errors
        if ($this->privateKeys === null) {
            $this->privateKeys = collect();
        }
        if ($this->servers === null) {
            $this->servers = collect();
        }
        if (! isset($this->projects)) {
            $this->projects = collect();
        }

        // Restore state when coming from URL with query params
        if ($this->selectedServerType === 'localhost' && $this->selectedExistingServer === 0) {
            $this->createdServer = Server::find(0);
            if ($this->createdServer) {
                $this->serverPublicKey = $this->createdServer->privateKey->getPublicKey();
            }
        }

        if ($this->selectedServerType === 'remote') {
            if ($this->privateKeys->isEmpty()) {
                $this->privateKeys = PrivateKey::ownedAndOnlySShKeys(['name'])->where('id', '!=', 0)->get();
            }
            if ($this->servers->isEmpty()) {
                $this->servers = Server::ownedByCurrentTeam(['name'])->where('id', '!=', 0)->get();
            }

            if ($this->selectedExistingServer) {
                $this->createdServer = Server::find($this->selectedExistingServer);
                if ($this->createdServer) {
                    $this->serverPublicKey = $this->createdServer->privateKey->getPublicKey();
                    $this->updateServerDetails();
                }
            }

            if ($this->selectedExistingPrivateKey) {
                $this->createdPrivateKey = PrivateKey::where('team_id', currentTeam()->id)
                    ->where('id', $this->selectedExistingPrivateKey)
                    ->first();
                if ($this->createdPrivateKey) {
                    $this->privateKey = $this->createdPrivateKey->private_key;
                    $this->publicKey = $this->createdPrivateKey->getPublicKey();
                }
            }

            // Auto-regenerate key pair for "Generate with Coolify" mode on page refresh
            if ($this->privateKeyType === 'create' && empty($this->privateKey)) {
                $this->createNewPrivateKey();
            }
        }

        if ($this->selectedProject) {
            $this->createdProject = Project::find($this->selectedProject);
            if (! $this->createdProject) {
                $this->projects = Project::ownedByCurrentTeam(['name'])->get();
            }
        }

        // Load projects when on create-project state (for page refresh)
        if ($this->currentState === 'create-project' && $this->projects->isEmpty()) {
            $this->projects = Project::ownedByCurrentTeam(['name'])->get();
        }
    }

    public function explanation()
    {
        if (isCloud()) {
            return $this->setServerType('remote');
        }
        $this->currentState = 'select-server-type';
    }

    public function restartBoarding()
    {
        return redirect()->route('onboarding');
    }

    public function skipBoarding()
    {
        Team::find(currentTeam()->id)->update([
            'show_boarding' => false,
        ]);
        refreshSession();

        return redirect()->route('dashboard');
    }

    public function setServerType(string $type)
    {
        $this->selectedServerType = $type;
        if ($this->selectedServerType === 'localhost') {
            $this->createdServer = Server::find(0);
            $this->selectedExistingServer = 0;
            if (! $this->createdServer) {
                return $this->dispatch('error', 'Localhost server is not found. Something went wrong during installation. Please try to reinstall or contact support.');
            }
            $this->serverPublicKey = $this->createdServer->privateKey->getPublicKey();

            return $this->validateServer('localhost');
        } elseif ($this->selectedServerType === 'remote') {
            $this->privateKeys = PrivateKey::ownedAndOnlySShKeys(['name'])->where('id', '!=', 0)->get();
            // Auto-select first key if available for better UX
            if ($this->privateKeys->count() > 0) {
                $this->selectedExistingPrivateKey = $this->privateKeys->first()->id;
            }
            // Onboarding always creates new servers, skip existing server selection
            $this->currentState = 'private-key';
        }
    }

    private function updateServerDetails()
    {
        if ($this->createdServer) {
            $this->remoteServerPort = $this->createdServer->port;
            $this->remoteServerUser = $this->createdServer->user;
        }
    }

    public function getProxyType()
    {
        $this->selectProxy(ProxyTypes::TRAEFIK->value);
        $this->getProjects();
    }

    public function selectExistingPrivateKey()
    {
        if (is_null($this->selectedExistingPrivateKey)) {
            $this->dispatch('error', 'Please select a private key.');

            return;
        }
        $this->createdPrivateKey = PrivateKey::where('team_id', currentTeam()->id)->where('id', $this->selectedExistingPrivateKey)->first();
        $this->privateKey = $this->createdPrivateKey->private_key;
        $this->currentState = 'create-server';
    }

    public function createNewServer()
    {
        $this->selectedExistingServer = null;
        $this->currentState = 'private-key';
    }

    public function setPrivateKey(string $type)
    {
        $this->selectedExistingPrivateKey = null;
        $this->privateKeyType = $type;
        if ($type === 'create') {
            $this->createNewPrivateKey();
        } else {
            $this->privateKey = null;
            $this->publicKey = null;
        }
        $this->currentState = 'create-private-key';
    }

    public function savePrivateKey()
    {
        $this->validate([
            'privateKeyName' => 'required|string|max:255',
            'privateKeyDescription' => 'nullable|string|max:255',
            'privateKey' => 'required|string',
        ]);

        try {
            $privateKey = PrivateKey::createAndStore([
                'name' => $this->privateKeyName,
                'description' => $this->privateKeyDescription,
                'private_key' => $this->privateKey,
                'team_id' => currentTeam()->id,
            ]);

            $this->createdPrivateKey = $privateKey;
            $this->currentState = 'create-server';
        } catch (\Exception $e) {
            $this->addError('privateKey', 'Failed to save private key: '.$e->getMessage());
        }
    }

    public function saveServer()
    {
        $this->validate([
            'remoteServerName' => 'required|string',
            'remoteServerHost' => 'required|string',
            'remoteServerPort' => 'required|integer',
            'remoteServerUser' => 'required|string',
        ]);

        $this->privateKey = formatPrivateKey($this->privateKey);
        $foundServer = Server::whereIp($this->remoteServerHost)->first();
        if ($foundServer) {
            return $this->dispatch('error', 'IP address is already in use by another team.');
        }
        $this->createdServer = Server::create([
            'name' => $this->remoteServerName,
            'ip' => $this->remoteServerHost,
            'port' => $this->remoteServerPort,
            'user' => $this->remoteServerUser,
            'description' => $this->remoteServerDescription,
            'private_key_id' => $this->createdPrivateKey->id,
            'team_id' => currentTeam()->id,
        ]);
        $this->createdServer->settings->is_swarm_manager = $this->isSwarmManager;
        $this->createdServer->settings->is_cloudflare_tunnel = $this->isCloudflareTunnel;
        $this->createdServer->settings->save();
        $this->selectedExistingServer = $this->createdServer->id;
        $this->currentState = 'validate-server';
    }

    public function installServer()
    {
        $this->dispatch('init', true);
    }

    public function validateServer()
    {
        try {
            $this->disableSshMux();

            // EC2 does not have `uptime` command, lol
            instant_remote_process(['ls /'], $this->createdServer, true);

            $this->createdServer->settings()->update([
                'is_reachable' => true,
            ]);
            $this->serverReachable = true;
        } catch (\Throwable $e) {
            $this->serverReachable = false;
            $this->createdServer->settings()->update([
                'is_reachable' => false,
            ]);

            return handleError(error: $e, livewire: $this);
        }

        try {
            // Check prerequisites
            $validationResult = $this->createdServer->validatePrerequisites();
            if (! $validationResult['success']) {
                // Check if we've exceeded max attempts
                if ($this->prerequisiteInstallAttempts >= $this->maxPrerequisiteInstallAttempts) {
                    $missingCommands = implode(', ', $validationResult['missing']);
                    throw new \Exception("Prerequisites ({$missingCommands}) could not be installed after {$this->maxPrerequisiteInstallAttempts} attempts. Please install them manually.");
                }

                // Start async installation and wait for completion via ActivityMonitor
                $activity = $this->createdServer->installPrerequisites();
                $this->prerequisiteInstallAttempts++;
                $this->dispatch('activityMonitor', $activity->id, 'prerequisitesInstalled');

                // Return early - handlePrerequisitesInstalled() will be called when installation completes
                return;
            }

            // Prerequisites are already installed, continue with validation
            $this->continueValidation();
        } catch (\Throwable $e) {
            return handleError(error: $e, livewire: $this);
        }
    }

    public function handlePrerequisitesInstalled()
    {
        try {
            // Revalidate prerequisites after installation completes
            $validationResult = $this->createdServer->validatePrerequisites();
            if (! $validationResult['success']) {
                // Installation completed but prerequisites still missing - retry
                $missingCommands = implode(', ', $validationResult['missing']);

                if ($this->prerequisiteInstallAttempts >= $this->maxPrerequisiteInstallAttempts) {
                    throw new \Exception("Prerequisites ({$missingCommands}) could not be installed after {$this->maxPrerequisiteInstallAttempts} attempts. Please install them manually.");
                }

                // Try again
                $activity = $this->createdServer->installPrerequisites();
                $this->prerequisiteInstallAttempts++;
                $this->dispatch('activityMonitor', $activity->id, 'prerequisitesInstalled');

                return;
            }

            // Prerequisites validated successfully - continue with Docker validation
            $this->continueValidation();
        } catch (\Throwable $e) {
            return handleError(error: $e, livewire: $this);
        }
    }

    private function continueValidation()
    {
        try {
            $dockerVersion = instant_remote_process(["docker version|head -2|grep -i version| awk '{print $2}'"], $this->createdServer, true);
            $dockerVersion = checkMinimumDockerEngineVersion($dockerVersion);
            if (is_null($dockerVersion)) {
                $this->currentState = 'validate-server';
                throw new \Exception('Docker not found or old version is installed.');
            }
            $this->createdServer->settings()->update([
                'is_usable' => true,
            ]);
            $this->getProxyType();
        } catch (\Throwable $e) {
            $this->createdServer->settings()->update([
                'is_usable' => false,
            ]);

            return handleError(error: $e, livewire: $this);
        }
    }

    public function selectProxy(?string $proxyType = null)
    {
        if (! $proxyType) {
            return $this->getProjects();
        }
        $this->createdServer->proxy->type = $proxyType;
        $this->createdServer->proxy->status = 'exited';
        $this->createdServer->proxy->last_saved_settings = null;
        $this->createdServer->proxy->last_applied_settings = null;
        $this->createdServer->save();
        $this->getProjects();
    }

    public function getProjects()
    {
        $this->projects = Project::ownedByCurrentTeam(['name'])->get();
        if ($this->projects->count() > 0) {
            $this->selectedProject = $this->projects->first()->id;
        }
        $this->currentState = 'create-project';
    }

    public function selectExistingProject()
    {
        $this->createdProject = Project::find($this->selectedProject);
        $this->currentState = 'create-resource';
    }

    public function createNewProject()
    {
        $this->createdProject = Project::create([
            'name' => 'My first project',
            'team_id' => currentTeam()->id,
            'uuid' => (string) new Cuid2,
        ]);
        $this->currentState = 'create-resource';
    }

    public function showNewResource()
    {
        $this->skipBoarding();

        return redirect()->route(
            'project.resource.create',
            [
                'project_uuid' => $this->createdProject->uuid,
                'environment_uuid' => $this->createdProject->environments->first()->uuid,
                'server' => $this->createdServer->id,
            ]
        );
    }

    public function saveAndValidateServer()
    {
        $this->validate([
            'remoteServerPort' => 'required|integer|min:1|max:65535',
            'remoteServerUser' => 'required|string',
        ]);

        $this->createdServer->update([
            'port' => $this->remoteServerPort,
            'user' => $this->remoteServerUser,
            'timezone' => 'UTC',
        ]);
        $this->validateServer();
    }

    private function createNewPrivateKey()
    {
        $this->privateKeyName = generate_random_name();
        $this->privateKeyDescription = 'Created by Coolify';
        ['private' => $this->privateKey, 'public' => $this->publicKey] = generateSSHKey();
    }

    private function disableSshMux(): void
    {
        $configRepository = app(ConfigurationRepository::class);
        $configRepository->disableSshMux();
    }

    public function render()
    {
        return view('livewire.boarding.index')->layout('layouts.boarding');
    }
}
