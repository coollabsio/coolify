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
use App\Services\DigitalOceanService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Collection;
use Livewire\Attributes\Locked;
use Livewire\Component;

class ByDigitalOcean extends Component
{
    use AuthorizesRequests;

    public int $current_step = 1;

    #[Locked]
    public Collection $available_tokens;

    #[Locked]
    public $private_keys;

    #[Locked]
    public $limit_reached;

    public ?int $selected_token_id = null;

    public ?string $selectedTokenUuid = null;

    public array $regions = [];

    public array $images = [];

    public array $sizes = [];

    public array $digitalOceanSshKeys = [];

    public ?string $selected_region = null;

    public string|int|null $selected_image = null;

    public ?string $selected_size = null;

    public array $selectedDigitalOceanSshKeyIds = [];

    public string $server_name = '';

    public ?int $private_key_id = null;

    public bool $loading_data = false;

    public ?string $provider_data_error = null;

    public bool $enable_ipv6 = true;

    public bool $monitoring = true;

    public bool $show_cloud_init_script = false;

    public ?string $cloud_init_script = null;

    public bool $save_cloud_init_script = false;

    public ?string $cloud_init_script_name = null;

    public ?int $selected_cloud_init_script_id = null;

    #[Locked]
    public Collection $saved_cloud_init_scripts;

    public bool $from_onboarding = false;

    public function mount(?string $selectedTokenUuid = null)
    {
        try {
            $this->authorize('viewAny', CloudProviderToken::class);
            $this->loadTokens();
            $this->selectTokenFromUrl($selectedTokenUuid);
            $this->loadSavedCloudInitScripts();
            $this->server_name = generate_random_name();
            $this->private_keys = PrivateKey::ownedAndOnlySShKeys()->where('id', '!=', 0)->get();

            if ($this->private_keys->count() > 0) {
                $this->private_key_id = $this->private_keys->first()->id;
            }

            if ($this->selectedTokenUuid) {
                $this->current_step = 2;
                $this->loading_data = true;
            }
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function loadSavedCloudInitScripts(): void
    {
        $this->saved_cloud_init_scripts = CloudInitScript::ownedByCurrentTeam()->get();
    }

    public function getListeners(): array
    {
        return [
            'tokenAdded.digitalocean' => 'handleTokenAdded',
            'privateKeyCreated' => 'handlePrivateKeyCreated',
            'modalClosed' => 'resetSelection',
        ];
    }

    public function resetSelection(): void
    {
        $this->selected_token_id = null;
        $this->current_step = 1;
        $this->cloud_init_script = null;
        $this->save_cloud_init_script = false;
        $this->cloud_init_script_name = null;
        $this->selected_cloud_init_script_id = null;
        $this->show_cloud_init_script = false;
        $this->selectedDigitalOceanSshKeyIds = [];
    }

    public function loadTokens(): void
    {
        $this->available_tokens = CloudProviderToken::ownedByCurrentTeam()
            ->where('provider', 'digitalocean')
            ->get();
    }

    public function handleTokenAdded($tokenId): void
    {
        $this->loadTokens();
        $this->selected_token_id = $tokenId;
        $this->nextStep();
    }

    public function handlePrivateKeyCreated($keyId): void
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
                'selected_region' => 'required|string',
                'selected_image' => 'required',
                'selected_size' => 'required|string',
                'private_key_id' => 'required|integer|exists:private_keys,id,team_id,'.currentTeam()->id,
                'selectedDigitalOceanSshKeyIds' => 'nullable|array',
                'selectedDigitalOceanSshKeyIds.*' => 'integer',
                'enable_ipv6' => 'required|boolean',
                'monitoring' => 'required|boolean',
                'show_cloud_init_script' => 'boolean',
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
            'selected_token_id.required' => 'Please select a DigitalOcean token.',
            'selected_token_id.exists' => 'Selected token not found.',
        ];
    }

    public function selectToken(int $tokenId): mixed
    {
        $this->selected_token_id = $tokenId;

        return $this->nextStep();
    }

    private function selectTokenFromUrl(?string $selectedTokenUuid): void
    {
        if (! $selectedTokenUuid) {
            return;
        }

        $token = $this->available_tokens->firstWhere('uuid', $selectedTokenUuid);

        if (! $token) {
            return;
        }

        $this->selectedTokenUuid = $selectedTokenUuid;
        $this->selected_token_id = $token->id;
    }

    private function getDigitalOceanToken(): string
    {
        if ($this->selected_token_id) {
            $token = $this->available_tokens->firstWhere('id', $this->selected_token_id);

            return $token ? $token->token : '';
        }

        return '';
    }

    public function nextStep()
    {
        $this->validate([
            'selected_token_id' => 'required|integer|exists:cloud_provider_tokens,id',
        ]);

        try {
            if (! $this->selectedTokenUuid) {
                $token = $this->available_tokens->firstWhere('id', $this->selected_token_id);

                if ($token) {
                    return $this->redirectRoute('server.create.token', [
                        'type' => 'digital-ocean',
                        'token_uuid' => $token->uuid,
                    ], navigate: true);
                }
            }

            $this->current_step = 2;
            $this->loading_data = true;
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function previousStep(): mixed
    {
        if ($this->selectedTokenUuid) {
            return $this->redirectRoute('server.create.type', ['type' => 'digital-ocean'], navigate: true);
        }

        $this->current_step = 1;

        return null;
    }

    public function loadDigitalOceanData(): void
    {
        $token = $this->getDigitalOceanToken();

        if (! $token) {
            $this->loading_data = false;
            $this->dispatch('error', 'Please select a valid DigitalOcean token.');

            return;
        }

        $this->loading_data = true;
        $this->provider_data_error = null;

        try {
            $digitalOceanService = new DigitalOceanService($token);

            $this->regions = $digitalOceanService->getRegions();
            $this->sizes = $digitalOceanService->getSizes();
            $this->images = collect($digitalOceanService->getImages())
                ->sortBy(fn (array $image) => ($image['distribution'] ?? '').' '.($image['name'] ?? ''))
                ->values()
                ->toArray();
            $this->digitalOceanSshKeys = $digitalOceanService->getSshKeys();
            $this->loading_data = false;
        } catch (\Throwable $e) {
            $this->loading_data = false;
            $this->provider_data_error = $this->providerDataErrorMessage('DigitalOcean', $e, 'message');
            $this->dispatch('error', $this->provider_data_error);
        }
    }

    private function providerDataErrorMessage(string $providerName, \Throwable $e, string $jsonMessageKey): string
    {
        $details = $e->getMessage();

        if ($e instanceof RequestException && $e->response) {
            $details = data_get($e->response->json(), $jsonMessageKey) ?: $e->response->body() ?: $details;
        }

        return "{$providerName} API error: {$details}";
    }

    public function getAvailableSizesProperty(): array
    {
        if (! $this->selected_region) {
            return $this->sizes;
        }

        return collect($this->sizes)
            ->filter(fn (array $size) => in_array($this->selected_region, $size['regions'] ?? []))
            ->values()
            ->toArray();
    }

    public function getAvailableImagesProperty(): array
    {
        if (! $this->selected_region) {
            return $this->images;
        }

        return collect($this->images)
            ->filter(fn (array $image) => in_array($this->selected_region, $image['regions'] ?? []))
            ->values()
            ->toArray();
    }

    public function getSelectedDropletPriceProperty(): ?string
    {
        if (! $this->selected_size) {
            return null;
        }

        $size = collect($this->sizes)->firstWhere('slug', $this->selected_size);
        if (! $size || ! isset($size['price_monthly'])) {
            return null;
        }

        return '$'.number_format((float) $size['price_monthly'], 2);
    }

    public function updatedSelectedRegion(): void
    {
        $this->selected_size = null;
        $this->selected_image = null;
    }

    public function updatedSelectedSize(): void
    {
        $this->selected_image = null;
    }

    public function updatedSelectedCloudInitScriptId($value): void
    {
        if ($value) {
            $script = CloudInitScript::ownedByCurrentTeam()->findOrFail($value);
            $this->cloud_init_script = $script->script;
            $this->cloud_init_script_name = $script->name;
            $this->show_cloud_init_script = true;
        }
    }

    public function updatedSaveCloudInitScript(bool $value): void
    {
        if (! $value) {
            $this->cloud_init_script_name = null;
        }
    }

    public function showCloudInitScript(): void
    {
        $this->show_cloud_init_script = true;
    }

    public function getAdvancedDigitalOceanOptionsSummaryProperty(): array
    {
        $summary = [];

        if (count($this->selectedDigitalOceanSshKeyIds) > 0) {
            $summary[] = count($this->selectedDigitalOceanSshKeyIds).' extra SSH '.str('key')->plural(count($this->selectedDigitalOceanSshKeyIds));
        }

        if (! $this->enable_ipv6) {
            $summary[] = 'IPv4 only';
        }

        if (! $this->monitoring) {
            $summary[] = 'Monitoring off';
        }

        if ($this->show_cloud_init_script || filled($this->cloud_init_script) || filled($this->selected_cloud_init_script_id)) {
            $summary[] = 'Cloud-init';
        }

        return $summary;
    }

    public function clearCloudInitScript(): void
    {
        $this->selected_cloud_init_script_id = null;
        $this->cloud_init_script = '';
        $this->cloud_init_script_name = '';
        $this->save_cloud_init_script = false;
        $this->show_cloud_init_script = false;
    }

    /**
     * @return array{droplet: array, ip: string|null}
     */
    private function createDigitalOceanDroplet(string $token): array
    {
        $digitalOceanService = new DigitalOceanService($token);
        $privateKey = PrivateKey::ownedByCurrentTeam()->findOrFail($this->private_key_id);
        $md5Fingerprint = PrivateKey::generateMd5Fingerprint($privateKey->private_key);

        $sshKeyId = null;
        foreach ($digitalOceanService->getSshKeys() as $key) {
            if (($key['fingerprint'] ?? null) === $md5Fingerprint) {
                $sshKeyId = (int) $key['id'];
                break;
            }
        }

        if (! $sshKeyId) {
            $uploadedKey = $digitalOceanService->uploadSshKey($privateKey->name, $privateKey->getPublicKey());
            $sshKeyId = (int) $uploadedKey['id'];
        }

        $sshKeys = array_values(array_unique(array_merge(
            [$sshKeyId],
            $this->selectedDigitalOceanSshKeyIds
        )));

        $params = [
            'name' => strtolower(trim($this->server_name)),
            'region' => $this->selected_region,
            'size' => $this->selected_size,
            'image' => $this->selected_image,
            'ssh_keys' => $sshKeys,
            'ipv6' => $this->enable_ipv6,
            'monitoring' => $this->monitoring,
        ];

        if (! empty($this->cloud_init_script)) {
            $params['user_data'] = $this->cloud_init_script;
        }

        $droplet = $digitalOceanService->createDroplet($params);
        $droplet = $digitalOceanService->waitForPublicIp($droplet, true, $this->enable_ipv6);
        $ipAddress = $digitalOceanService->getPublicIpAddress($droplet, true, $this->enable_ipv6);

        return [
            'droplet' => $droplet,
            'ip' => $ipAddress,
        ];
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

            $result = $this->createDigitalOceanDroplet($this->getDigitalOceanToken());
            $droplet = $result['droplet'];
            $ipAddress = $result['ip'];

            if (! $ipAddress) {
                throw new \Exception('No public IP address available for the new droplet.');
            }

            $server = Server::create([
                'name' => strtolower(trim($this->server_name)),
                'ip' => $ipAddress,
                'user' => 'root',
                'port' => 22,
                'team_id' => currentTeam()->id,
                'private_key_id' => $this->private_key_id,
                'cloud_provider_token_id' => $this->selected_token_id,
                'digitalocean_droplet_id' => $droplet['id'],
                'digitalocean_droplet_status' => $droplet['status'] ?? null,
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
        return view('livewire.server.new.by-digital-ocean');
    }
}
