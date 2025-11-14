<?php

namespace App\Livewire\Server\New;

use App\Enums\ProxyTypes;
use App\Models\CloudInitScript;
use App\Models\CloudProviderToken;
use App\Models\PrivateKey;
use App\Models\Server;
use App\Models\Team;
use App\Rules\ValidHostname;
use App\Services\HetznerService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Livewire\Attributes\Locked;
use Livewire\Component;

class ByHetzner extends Component
{
    use AuthorizesRequests;

    // Step tracking
    public int $current_step = 1;

    // Locked data
    #[Locked]
    public Collection $available_tokens;

    #[Locked]
    public $private_keys;

    #[Locked]
    public $limit_reached;

    // Step 1: Token selection
    public ?int $selected_token_id = null;

    // Step 2: Server configuration
    public array $locations = [];

    public array $images = [];

    public array $serverTypes = [];

    public array $hetznerSshKeys = [];

    public ?string $selected_location = null;

    public ?int $selected_image = null;

    public ?string $selected_server_type = null;

    public array $selectedHetznerSshKeyIds = [];

    public string $server_name = '';

    public ?int $private_key_id = null;

    public bool $loading_data = false;

    public bool $enable_ipv4 = true;

    public bool $enable_ipv6 = true;

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
            ->where('provider', 'hetzner')
            ->get();
    }

    public function handleTokenAdded($tokenId)
    {
        // Refresh token list
        $this->loadTokens();

        // Auto-select the new token
        $this->selected_token_id = $tokenId;

        // Automatically proceed to next step
        $this->nextStep();
    }

    public function handlePrivateKeyCreated($keyId)
    {
        // Refresh private keys list
        $this->private_keys = PrivateKey::ownedAndOnlySShKeys()->where('id', '!=', 0)->get();

        // Auto-select the new key
        $this->private_key_id = $keyId;

        // Clear validation errors for private_key_id
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
                'selected_location' => 'required|string',
                'selected_image' => 'required|integer',
                'selected_server_type' => 'required|string',
                'private_key_id' => 'required|integer|exists:private_keys,id,team_id,'.currentTeam()->id,
                'selectedHetznerSshKeyIds' => 'nullable|array',
                'selectedHetznerSshKeyIds.*' => 'integer',
                'enable_ipv4' => 'required|boolean',
                'enable_ipv6' => 'required|boolean',
                'cloud_init_script' => ['nullable', 'string', new \App\Rules\ValidCloudInitYaml],
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
            'selected_token_id.required' => 'Please select a Hetzner token.',
            'selected_token_id.exists' => 'Selected token not found.',
        ];
    }

    public function selectToken(int $tokenId)
    {
        $this->selected_token_id = $tokenId;
    }

    private function validateHetznerToken(string $token): bool
    {
        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer '.$token,
            ])->timeout(10)->get('https://api.hetzner.cloud/v1/servers');

            return $response->successful();
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function getHetznerToken(): string
    {
        if ($this->selected_token_id) {
            $token = $this->available_tokens->firstWhere('id', $this->selected_token_id);

            return $token ? $token->token : '';
        }

        return '';
    }

    public function nextStep()
    {
        // Validate step 1 - just need a token selected
        $this->validate([
            'selected_token_id' => 'required|integer|exists:cloud_provider_tokens,id',
        ]);

        try {
            $hetznerToken = $this->getHetznerToken();

            if (! $hetznerToken) {
                return $this->dispatch('error', 'Please select a valid Hetzner token.');
            }

            // Load Hetzner data
            $this->loadHetznerData($hetznerToken);

            // Move to step 2
            $this->current_step = 2;
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function previousStep()
    {
        $this->current_step = 1;
    }

    private function loadHetznerData(string $token)
    {
        $this->loading_data = true;

        try {
            $hetznerService = new HetznerService($token);

            $this->locations = $hetznerService->getLocations();
            $this->serverTypes = $hetznerService->getServerTypes();

            // Get images and sort by name
            $images = $hetznerService->getImages();

            $this->images = collect($images)
                ->filter(function ($image) {
                    // Only system images
                    if (! isset($image['type']) || $image['type'] !== 'system') {
                        return false;
                    }

                    // Filter out deprecated images
                    if (isset($image['deprecated']) && $image['deprecated'] === true) {
                        return false;
                    }

                    return true;
                })
                ->sortBy('name')
                ->values()
                ->toArray();
            // Load SSH keys from Hetzner
            $this->hetznerSshKeys = $hetznerService->getSshKeys();
            $this->loading_data = false;
        } catch (\Throwable $e) {
            $this->loading_data = false;
            throw $e;
        }
    }

    private function getCpuVendorInfo(array $serverType): ?string
    {
        $name = strtolower($serverType['name'] ?? '');

        if (str_starts_with($name, 'ccx')) {
            return 'AMD Milan EPYC™';
        } elseif (str_starts_with($name, 'cpx')) {
            return 'AMD EPYC™';
        } elseif (str_starts_with($name, 'cx')) {
            return 'Intel®/AMD';
        } elseif (str_starts_with($name, 'cax')) {
            return 'Ampere®';
        }

        return null;
    }

    public function getAvailableServerTypesProperty()
    {
        ray('Getting available server types', [
            'selected_location' => $this->selected_location,
            'total_server_types' => count($this->serverTypes),
        ]);

        if (! $this->selected_location) {
            return $this->serverTypes;
        }

        $filtered = collect($this->serverTypes)
            ->filter(function ($type) {
                if (! isset($type['locations'])) {
                    return false;
                }

                $locationNames = collect($type['locations'])->pluck('name')->toArray();

                return in_array($this->selected_location, $locationNames);
            })
            ->map(function ($serverType) {
                $serverType['cpu_vendor_info'] = $this->getCpuVendorInfo($serverType);

                return $serverType;
            })
            ->values()
            ->toArray();

        ray('Filtered server types', [
            'selected_location' => $this->selected_location,
            'filtered_count' => count($filtered),
        ]);

        return $filtered;
    }

    public function getAvailableImagesProperty()
    {
        ray('Getting available images', [
            'selected_server_type' => $this->selected_server_type,
            'total_images' => count($this->images),
            'images' => $this->images,
        ]);

        if (! $this->selected_server_type) {
            return $this->images;
        }

        $serverType = collect($this->serverTypes)->firstWhere('name', $this->selected_server_type);

        ray('Server type data', $serverType);

        if (! $serverType || ! isset($serverType['architecture'])) {
            ray('No architecture in server type, returning all');

            return $this->images;
        }

        $architecture = $serverType['architecture'];

        $filtered = collect($this->images)
            ->filter(fn ($image) => ($image['architecture'] ?? null) === $architecture)
            ->values()
            ->toArray();

        ray('Filtered images', [
            'architecture' => $architecture,
            'filtered_count' => count($filtered),
        ]);

        return $filtered;
    }

    public function getSelectedServerPriceProperty(): ?string
    {
        if (! $this->selected_server_type) {
            return null;
        }

        $serverType = collect($this->serverTypes)->firstWhere('name', $this->selected_server_type);

        if (! $serverType || ! isset($serverType['prices'][0]['price_monthly']['gross'])) {
            return null;
        }

        $price = $serverType['prices'][0]['price_monthly']['gross'];

        return '€'.number_format($price, 2);
    }

    public function updatedSelectedLocation($value)
    {
        ray('Location selected', $value);

        // Reset server type and image when location changes
        $this->selected_server_type = null;
        $this->selected_image = null;
    }

    public function updatedSelectedServerType($value)
    {
        ray('Server type selected', $value);

        // Reset image when server type changes
        $this->selected_image = null;
    }

    public function updatedSelectedImage($value)
    {
        ray('Image selected', $value);
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

    private function createHetznerServer(string $token): array
    {
        $hetznerService = new HetznerService($token);

        // Get the private key and extract public key
        $privateKey = PrivateKey::ownedByCurrentTeam()->findOrFail($this->private_key_id);

        $publicKey = $privateKey->getPublicKey();
        $md5Fingerprint = PrivateKey::generateMd5Fingerprint($privateKey->private_key);

        ray('Private Key Info', [
            'private_key_id' => $this->private_key_id,
            'sha256_fingerprint' => $privateKey->fingerprint,
            'md5_fingerprint' => $md5Fingerprint,
        ]);

        // Check if SSH key already exists on Hetzner by comparing MD5 fingerprints
        $existingSshKeys = $hetznerService->getSshKeys();
        $existingKey = null;

        ray('Existing SSH Keys on Hetzner', $existingSshKeys);

        foreach ($existingSshKeys as $key) {
            if ($key['fingerprint'] === $md5Fingerprint) {
                $existingKey = $key;
                break;
            }
        }

        // Upload SSH key if it doesn't exist
        if ($existingKey) {
            $sshKeyId = $existingKey['id'];
            ray('Using existing SSH key', ['ssh_key_id' => $sshKeyId]);
        } else {
            $sshKeyName = $privateKey->name;
            $uploadedKey = $hetznerService->uploadSshKey($sshKeyName, $publicKey);
            $sshKeyId = $uploadedKey['id'];
            ray('Uploaded new SSH key', ['ssh_key_id' => $sshKeyId, 'name' => $sshKeyName]);
        }

        // Normalize server name to lowercase for RFC 1123 compliance
        $normalizedServerName = strtolower(trim($this->server_name));

        // Prepare SSH keys array: Coolify key + user-selected Hetzner keys
        $sshKeys = array_merge(
            [$sshKeyId], // Coolify key (always included)
            $this->selectedHetznerSshKeyIds // User-selected Hetzner keys
        );

        // Remove duplicates in case the Coolify key was also selected
        $sshKeys = array_unique($sshKeys);
        $sshKeys = array_values($sshKeys); // Re-index array

        // Prepare server creation parameters
        $params = [
            'name' => $normalizedServerName,
            'server_type' => $this->selected_server_type,
            'image' => $this->selected_image,
            'location' => $this->selected_location,
            'start_after_create' => true,
            'ssh_keys' => $sshKeys,
            'public_net' => [
                'enable_ipv4' => $this->enable_ipv4,
                'enable_ipv6' => $this->enable_ipv6,
            ],
        ];

        // Add cloud-init script if provided
        if (! empty($this->cloud_init_script)) {
            $params['user_data'] = $this->cloud_init_script;
        }

        ray('Server creation parameters', $params);

        // Create server on Hetzner
        $hetznerServer = $hetznerService->createServer($params);

        ray('Hetzner server created', $hetznerServer);

        return $hetznerServer;
    }

    public function submit()
    {
        $this->validate();

        try {
            $this->authorize('create', Server::class);

            if (Team::serverLimitReached()) {
                return $this->dispatch('error', 'You have reached the server limit for your subscription.');
            }

            // Save cloud-init script if requested
            if ($this->save_cloud_init_script && ! empty($this->cloud_init_script) && ! empty($this->cloud_init_script_name)) {
                $this->authorize('create', CloudInitScript::class);

                CloudInitScript::create([
                    'team_id' => currentTeam()->id,
                    'name' => $this->cloud_init_script_name,
                    'script' => $this->cloud_init_script,
                ]);
            }

            $hetznerToken = $this->getHetznerToken();

            // Create server on Hetzner
            $hetznerServer = $this->createHetznerServer($hetznerToken);

            // Determine IP address to use (prefer IPv4, fallback to IPv6)
            $ipAddress = null;
            if ($this->enable_ipv4 && isset($hetznerServer['public_net']['ipv4']['ip'])) {
                $ipAddress = $hetznerServer['public_net']['ipv4']['ip'];
            } elseif ($this->enable_ipv6 && isset($hetznerServer['public_net']['ipv6']['ip'])) {
                $ipAddress = $hetznerServer['public_net']['ipv6']['ip'];
            }

            if (! $ipAddress) {
                throw new \Exception('No public IP address available. Enable at least one of IPv4 or IPv6.');
            }

            // Create server in Coolify database
            $server = Server::create([
                'name' => $this->server_name,
                'ip' => $ipAddress,
                'user' => 'root',
                'port' => 22,
                'team_id' => currentTeam()->id,
                'private_key_id' => $this->private_key_id,
                'cloud_provider_token_id' => $this->selected_token_id,
                'hetzner_server_id' => $hetznerServer['id'],
            ]);

            $server->proxy->set('status', 'exited');
            $server->proxy->set('type', ProxyTypes::TRAEFIK->value);
            $server->save();

            if ($this->from_onboarding) {
                // Complete the boarding when server is successfully created via Hetzner
                currentTeam()->update([
                    'show_boarding' => false,
                ]);
                refreshSession();

                return $this->redirect(route('server.show', $server->uuid));
            }

            return redirect()->route('server.show', $server->uuid);
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function render()
    {
        return view('livewire.server.new.by-hetzner');
    }
}
