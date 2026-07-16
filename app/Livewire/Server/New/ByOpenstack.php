<?php

namespace App\Livewire\Server\New;

use App\Enums\ProxyTypes;
use App\Models\CloudInitScript;
use App\Models\CloudProviderToken;
use App\Models\PrivateKey;
use App\Models\Server;
use App\Models\Team;
use App\Rules\ValidCloudInitYaml;
use App\Rules\ValidHostname;
use App\Services\OpenStackService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Collection;
use Livewire\Attributes\Locked;
use Livewire\Component;

class ByOpenstack extends Component
{
    use AuthorizesRequests;

    /**
     * Max attempts (and delay per attempt) when polling OpenStack for a bootstrapped
     * port / fixed IP after a server has been requested.
     */
    private const POLL_ATTEMPTS = 20;

    private const POLL_DELAY_SECONDS = 3;

    // Step tracking
    public int $current_step = 1;

    #[Locked]
    public Collection $available_tokens;

    #[Locked]
    public $private_keys;

    #[Locked]
    public $limit_reached;

    // Step 1: Token selection
    public ?int $selected_token_id = null;

    // Step 2: Server configuration
    public array $availabilityZones = [];

    public array $flavors = [];

    public array $images = [];

    public array $networks = [];

    public array $externalNetworks = [];

    public ?string $selected_availability_zone = null;

    public ?string $selected_flavor = null;

    public ?int $volume_size = null;

    public ?string $selected_image = null;

    public ?string $selected_network = null;

    public ?string $selected_external_network = null;

    public bool $assign_floating_ip = true;

    public string $server_name = '';

    public string $server_user = 'root';

    public ?int $private_key_id = null;

    public bool $loading_data = false;

    public ?string $cloud_init_script = null;

    public bool $save_cloud_init_script = false;

    public ?string $cloud_init_script_name = null;

    public ?int $selected_cloud_init_script_id = null;

    #[Locked]
    public Collection $saved_cloud_init_scripts;

    public bool $from_onboarding = false;

    public function mount()
    {
        $this->authorize('viewAny', CloudProviderToken::class);
        $this->loadTokens();
        $this->loadSavedCloudInitScripts();
        $this->server_name = generate_random_name();
        $this->private_keys = PrivateKey::ownedAndOnlySShKeys()->where('id', '!=', 0)->get();

        if ($this->private_keys->count() > 0) {
            $this->private_key_id = $this->private_keys->first()->id;
        }
    }

    public function loadSavedCloudInitScripts()
    {
        $this->saved_cloud_init_scripts = CloudInitScript::ownedByCurrentTeam()->get();
    }

    public function getListeners()
    {
        return [
            'tokenAdded' => 'handleTokenAdded',
            'privateKeyCreated' => 'handlePrivateKeyCreated',
            'modalClosed' => 'resetSelection',
        ];
    }

    public function resetSelection()
    {
        $this->selected_token_id = null;
        $this->current_step = 1;
        $this->cloud_init_script = null;
        $this->save_cloud_init_script = false;
        $this->cloud_init_script_name = null;
        $this->selected_cloud_init_script_id = null;
    }

    public function loadTokens()
    {
        $this->available_tokens = CloudProviderToken::ownedByCurrentTeam()
            ->where('provider', 'openstack')
            ->get();
    }

    public function handleTokenAdded($tokenId)
    {
        $this->loadTokens();
        $this->selected_token_id = $tokenId;
        $this->nextStep();
    }

    public function handlePrivateKeyCreated($keyId)
    {
        $this->private_keys = PrivateKey::ownedAndOnlySShKeys()->where('id', '!=', 0)->get();
        $this->private_key_id = $keyId;
        $this->resetErrorBag('private_key_id');
    }

    protected function rules(): array
    {
        $rules = [
            'selected_token_id' => 'required|integer|exists:cloud_provider_tokens,id',
        ];

        if ($this->current_step === 2) {
            $rules = array_merge($rules, [
                'server_name' => ['required', 'string', 'max:253', new ValidHostname],
                'server_user' => 'required|string|max:255',
                'selected_availability_zone' => 'nullable|string',
                'selected_flavor' => 'required|string',
                'volume_size' => [$this->selectedFlavorIsDiskless() ? 'required' : 'nullable', 'integer', 'min:1', 'max:16384'],
                'selected_image' => 'required|string',
                'selected_network' => 'required|string',
                'assign_floating_ip' => 'required|boolean',
                'selected_external_network' => 'nullable|string|required_if:assign_floating_ip,true',
                'private_key_id' => 'required|integer|exists:private_keys,id,team_id,'.currentTeam()->id,
                'cloud_init_script' => ['nullable', 'string', new ValidCloudInitYaml],
                'save_cloud_init_script' => 'boolean',
                'cloud_init_script_name' => 'nullable|string|max:255',
                'selected_cloud_init_script_id' => 'nullable|integer|exists:cloud_init_scripts,id',
            ]);
        }

        return $rules;
    }

    protected function messages(): array
    {
        return [
            'selected_token_id.required' => 'Please select an OpenStack credential.',
            'selected_token_id.exists' => 'Selected credential not found.',
            'selected_external_network.required_if' => 'Please select an external network for the floating IP.',
            'volume_size.required' => 'This flavor has no local disk, so a root volume size (GB) is required.',
        ];
    }

    public function selectToken(int $tokenId)
    {
        $this->selected_token_id = $tokenId;
    }

    /**
     * Diskless flavors (root disk = 0) must boot from a volume, which is common
     * on SCS clouds. In that case a root volume size is required.
     */
    public function selectedFlavorIsDiskless(): bool
    {
        if (! $this->selected_flavor) {
            return false;
        }

        $flavor = collect($this->flavors)->firstWhere('id', $this->selected_flavor);

        return $flavor !== null && (int) ($flavor['disk'] ?? 0) === 0;
    }

    private function getOpenstackService(): OpenStackService
    {
        $token = $this->available_tokens->firstWhere('id', $this->selected_token_id);

        if (! $token) {
            throw new \Exception('Please select a valid OpenStack credential.');
        }

        return new OpenStackService($token->credentials());
    }

    public function nextStep()
    {
        $this->validate([
            'selected_token_id' => 'required|integer|exists:cloud_provider_tokens,id',
        ]);

        try {
            $this->loadOpenstackData();
            $this->current_step = 2;
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function previousStep()
    {
        $this->current_step = 1;
    }

    private function loadOpenstackData()
    {
        $this->loading_data = true;

        try {
            $service = $this->getOpenstackService();

            $this->flavors = collect($service->getFlavors())
                ->sortBy('name')
                ->values()
                ->toArray();

            $this->images = collect($service->getImages())
                ->sortBy('name')
                ->values()
                ->toArray();

            $networks = $service->getNetworks();

            $this->networks = collect($networks)
                ->filter(fn ($network) => ($network['router:external'] ?? false) !== true)
                ->sortBy('name')
                ->values()
                ->toArray();

            $this->externalNetworks = collect($networks)
                ->filter(fn ($network) => ($network['router:external'] ?? false) === true)
                ->sortBy('name')
                ->values()
                ->toArray();

            $this->availabilityZones = collect($service->getAvailabilityZones())
                ->sortBy('zoneName')
                ->values()
                ->toArray();

            // Default the external network to the only option when unambiguous.
            if (count($this->externalNetworks) === 1) {
                $this->selected_external_network = $this->externalNetworks[0]['id'];
            }

            $this->loading_data = false;
        } catch (\Throwable $e) {
            $this->loading_data = false;
            throw $e;
        }
    }

    public function updatedSelectedCloudInitScriptId($value)
    {
        if ($value) {
            $script = CloudInitScript::ownedByCurrentTeam()->findOrFail($value);
            $this->cloud_init_script = $script->script;
            $this->cloud_init_script_name = $script->name;
        }
    }

    public function clearCloudInitScript()
    {
        $this->selected_cloud_init_script_id = null;
        $this->cloud_init_script = '';
        $this->cloud_init_script_name = '';
        $this->save_cloud_init_script = false;
    }

    /**
     * Ensure the selected private key exists as a keypair on OpenStack and
     * return the keypair name to boot with.
     */
    private function ensureKeypair(OpenStackService $service, PrivateKey $privateKey): string
    {
        $existing = $service->findKeypairByName($privateKey->name);

        if ($existing) {
            return $existing['name'];
        }

        $uploaded = $service->uploadKeypair($privateKey->name, $privateKey->getPublicKey());

        return $uploaded['name'] ?? $privateKey->name;
    }

    /**
     * @return array{ip: string, floating_ip_id: ?string}
     */
    private function resolveServerAddress(OpenStackService $service, string $serverId, string $securityGroupId): array
    {
        // Wait for the instance's network port (needed to attach the security
        // group and to associate a floating IP).
        $portId = null;
        for ($attempt = 0; $attempt < self::POLL_ATTEMPTS; $attempt++) {
            $portId = $service->getServerPortId($serverId);
            if ($portId) {
                break;
            }
            sleep(self::POLL_DELAY_SECONDS);
        }

        if (! $portId) {
            throw new \Exception('Timed out waiting for the OpenStack instance network port.');
        }

        // Open the ports Coolify needs on top of the default group.
        $service->attachSecurityGroupToPort($portId, $securityGroupId);

        if ($this->assign_floating_ip) {
            $floatingIp = $service->allocateFloatingIp($this->selected_external_network, $portId);

            $ip = $floatingIp['floating_ip_address'] ?? null;

            if (! $ip) {
                throw new \Exception('Failed to allocate a floating IP for the OpenStack instance.');
            }

            return ['ip' => $ip, 'floating_ip_id' => $floatingIp['id'] ?? null];
        }

        // No floating IP: wait for the fixed IP to be assigned.
        for ($attempt = 0; $attempt < self::POLL_ATTEMPTS; $attempt++) {
            $server = $service->getServer($serverId);
            $ip = $service->getServerFixedIp($server);
            if ($ip) {
                return ['ip' => $ip, 'floating_ip_id' => null];
            }
            sleep(self::POLL_DELAY_SECONDS);
        }

        throw new \Exception('Timed out waiting for the OpenStack instance to receive an IP address.');
    }

    public function submit()
    {
        $this->validate();

        try {
            $this->authorize('create', Server::class);

            if (Team::serverLimitReached()) {
                return $this->dispatch('error', 'You have reached the server limit for your subscription.');
            }

            if ($this->save_cloud_init_script && ! empty($this->cloud_init_script) && ! empty($this->cloud_init_script_name)) {
                $this->authorize('create', CloudInitScript::class);

                CloudInitScript::create([
                    'team_id' => currentTeam()->id,
                    'name' => $this->cloud_init_script_name,
                    'script' => $this->cloud_init_script,
                ]);
            }

            $service = $this->getOpenstackService();

            $privateKey = PrivateKey::ownedByCurrentTeam()->findOrFail($this->private_key_id);
            $keyName = $this->ensureKeypair($service, $privateKey);

            // Ensure the instance is reachable (OpenStack's default security
            // group blocks external SSH/HTTP). Attached to the port after boot.
            $securityGroupId = $service->ensureCoolifySecurityGroup();

            $normalizedServerName = strtolower(trim($this->server_name));

            $openstackServer = $service->createServer([
                'name' => $normalizedServerName,
                'imageRef' => $this->selected_image,
                'flavorRef' => $this->selected_flavor,
                'networkId' => $this->selected_network,
                'key_name' => $keyName,
                'availabilityZone' => $this->selected_availability_zone,
                'volumeSize' => $this->volume_size,
                'userData' => $this->cloud_init_script,
            ]);

            $openstackServerId = $openstackServer['id'] ?? null;

            if (! $openstackServerId) {
                throw new \Exception('OpenStack did not return a server id.');
            }

            $address = $this->resolveServerAddress($service, $openstackServerId, $securityGroupId);

            $server = Server::create([
                'name' => $this->server_name,
                'ip' => $address['ip'],
                'user' => $this->server_user,
                'port' => 22,
                'team_id' => currentTeam()->id,
                'private_key_id' => $this->private_key_id,
                'cloud_provider_token_id' => $this->selected_token_id,
                'openstack_server_id' => $openstackServerId,
                'openstack_floating_ip_id' => $address['floating_ip_id'],
            ]);

            $server->proxy->set('status', 'exited');
            $server->proxy->set('type', ProxyTypes::TRAEFIK->value);
            $server->save();

            if ($this->from_onboarding) {
                currentTeam()->update([
                    'show_boarding' => false,
                ]);
                refreshSession();
            }

            return redirectRoute($this, 'server.show', [$server->uuid]);
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function render()
    {
        return view('livewire.server.new.by-openstack');
    }
}
