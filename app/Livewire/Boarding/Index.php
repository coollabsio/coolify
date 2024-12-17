<?php

namespace App\Livewire\Boarding;

use App\Enums\ProxyTypes;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Server;
use App\Models\Team;
use Illuminate\Support\Collection;
use Livewire\Component;
use Visus\Cuid2\Cuid2;

class Index extends Component
{
    protected $listeners = ['refreshBoardingIndex' => 'validateServer'];

    public string $currentState = 'welcome';

    public ?string $selectedServerType = null;

    public ?Collection $privateKeys = null;

    public ?int $selectedExistingPrivateKey = null;

    public ?string $privateKeyType = null;

    public ?string $privateKey = null;

    public ?string $publicKey = null;

    public ?string $privateKeyName = null;

    public ?string $privateKeyDescription = null;

    public ?PrivateKey $createdPrivateKey = null;

    public ?Collection $servers = null;

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

    public ?int $selectedProject = null;

    public ?Project $createdProject = null;

    public bool $dockerInstallationStarted = false;

    public string $serverPublicKey;

    public bool $serverReachable = true;

    public ?string $minDockerVersion = null;

    public function mount()
    {
        if (auth()->user()?->isMember() && auth()->user()->currentTeam()->show_boarding === true) {
            return redirect()->route('dashboard');
        }

        $this->minDockerVersion = str(config('constants.docker.minimum_required_version'))->before('.');
        $this->privateKeyName = generate_random_name();
        $this->remoteServerName = generate_random_name();
        if (isDev()) {
            $this->privateKey = '-----BEGIN OPENSSH PRIVATE KEY-----
b3BlbnNzaC1rZXktdjEAAAAABG5vbmUAAAAEbm9uZQAAAAAAAAABAAAAMwAAAAtzc2gtZW
QyNTUxOQAAACBbhpqHhqv6aI67Mj9abM3DVbmcfYhZAhC7ca4d9UCevAAAAJi/QySHv0Mk
hwAAAAtzc2gtZWQyNTUxOQAAACBbhpqHhqv6aI67Mj9abM3DVbmcfYhZAhC7ca4d9UCevA
AAAECBQw4jg1WRT2IGHMncCiZhURCts2s24HoDS0thHnnRKVuGmoeGq/pojrsyP1pszcNV
uZx9iFkCELtxrh31QJ68AAAAEXNhaWxANzZmZjY2ZDJlMmRkAQIDBA==
-----END OPENSSH PRIVATE KEY-----';
            $this->privateKeyDescription = 'Created by Coolify';
            $this->remoteServerDescription = 'Created by Coolify';
            $this->remoteServerHost = 'coolify-testing-host';
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
            if (isDev()) {
                $this->privateKeys = PrivateKey::ownedByCurrentTeam(['name'])->get();
            } else {
                $this->privateKeys = PrivateKey::ownedByCurrentTeam(['name'])->where('id', '!=', 0)->get();
            }
            if ($this->privateKeys->count() > 0) {
                $this->selectedExistingPrivateKey = $this->privateKeys->first()->id;
            }
            $this->servers = Server::ownedByCurrentTeam(['name'])->where('id', '!=', 0)->get();
            if ($this->servers->count() > 0) {
                $this->selectedExistingServer = $this->servers->first()->id;
                $this->updateServerDetails();
                $this->currentState = 'select-existing-server';

                return;
            }
            $this->currentState = 'private-key';
        }
    }

    public function selectExistingServer()
    {
        $this->createdServer = Server::find($this->selectedExistingServer);
        if (! $this->createdServer) {
            $this->dispatch('error', 'Server is not found.');
            $this->currentState = 'private-key';

            return;
        }
        $this->selectedExistingPrivateKey = $this->createdServer->privateKey->id;
        $this->serverPublicKey = $this->createdServer->privateKey->getPublicKey();
        $this->updateServerDetails();
        $this->currentState = 'validate-server';
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
            $this->restartBoarding();

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
            config()->set('constants.ssh.mux_enabled', false);

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

    public function render()
    {
        return view('livewire.boarding.index')->layout('layouts.boarding');
    }
}
