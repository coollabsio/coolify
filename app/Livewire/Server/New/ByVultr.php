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
use App\Services\VultrService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Collection;
use Livewire\Attributes\Locked;
use Livewire\Component;

class ByVultr extends Component
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

    public array $plans = [];

    public array $operatingSystems = [];

    public array $vultrSshKeys = [];

    public ?string $selected_region = null;

    public ?string $selected_plan = null;

    public ?int $selected_os_id = null;

    public array $selectedVultrSshKeyIds = [];

    public string $server_name = '';

    public ?int $private_key_id = null;

    public bool $loading_data = false;

    public ?string $provider_data_error = null;

    public bool $enable_ipv6 = true;

    public bool $disable_public_ipv4 = false;

    public ?string $cloud_init_script = null;

    public bool $save_cloud_init_script = false;

    public ?string $cloud_init_script_name = null;

    public ?int $selected_cloud_init_script_id = null;

    #[Locked]
    public Collection $saved_cloud_init_scripts;

    public bool $from_onboarding = false;

    public function mount(?string $selectedTokenUuid = null): void
    {
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
    }

    public function getListeners(): array
    {
        return [
            'tokenAdded' => 'handleTokenAdded',
            'privateKeyCreated' => 'handlePrivateKeyCreated',
            'modalClosed' => 'resetSelection',
        ];
    }

    public function loadTokens(): void
    {
        $this->available_tokens = CloudProviderToken::ownedByCurrentTeam()
            ->where('provider', 'vultr')
            ->get();
    }

    public function loadSavedCloudInitScripts(): void
    {
        $this->saved_cloud_init_scripts = CloudInitScript::ownedByCurrentTeam()->get();
    }

    public function resetSelection(): void
    {
        $this->selected_token_id = null;
        $this->current_step = 1;
        $this->cloud_init_script = null;
        $this->save_cloud_init_script = false;
        $this->cloud_init_script_name = null;
        $this->selected_cloud_init_script_id = null;
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
                'selected_plan' => 'required|string',
                'selected_os_id' => 'required|integer',
                'private_key_id' => 'required|integer|exists:private_keys,id,team_id,'.currentTeam()->id,
                'selectedVultrSshKeyIds' => 'nullable|array',
                'selectedVultrSshKeyIds.*' => 'string',
                'enable_ipv6' => 'required|boolean',
                'disable_public_ipv4' => 'required|boolean',
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
            'selected_token_id.required' => 'Please select a Vultr token.',
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

    public function nextStep(): mixed
    {
        $this->validate([
            'selected_token_id' => 'required|integer|exists:cloud_provider_tokens,id',
        ]);

        try {
            if (! $this->selectedTokenUuid) {
                $token = $this->available_tokens->firstWhere('id', $this->selected_token_id);

                if ($token) {
                    return $this->redirectRoute('server.create.token', [
                        'type' => 'vultr',
                        'token_uuid' => $token->uuid,
                    ], navigate: true);
                }
            }

            $this->current_step = 2;
            $this->loading_data = true;
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }

        return null;
    }

    public function previousStep(): mixed
    {
        if ($this->selectedTokenUuid) {
            return $this->redirectRoute('server.create.type', ['type' => 'vultr'], navigate: true);
        }

        $this->current_step = 1;

        return null;
    }

    public function updatedSelectedRegion(): void
    {
        $this->selected_plan = null;
    }

    public function updatedSelectedCloudInitScriptId($value): void
    {
        if ($value) {
            $script = CloudInitScript::ownedByCurrentTeam()->findOrFail($value);
            $this->cloud_init_script = $script->script;
            $this->cloud_init_script_name = $script->name;
        }
    }

    public function clearCloudInitScript(): void
    {
        $this->selected_cloud_init_script_id = null;
        $this->cloud_init_script = '';
        $this->cloud_init_script_name = '';
        $this->save_cloud_init_script = false;
    }

    public function getAvailablePlansProperty(): array
    {
        if (! $this->selected_region) {
            return $this->plans;
        }

        return collect($this->plans)
            ->filter(function ($plan) {
                $locations = $plan['locations'] ?? [];

                return empty($locations) || in_array($this->selected_region, $locations);
            })
            ->values()
            ->toArray();
    }

    public function getSelectedServerPriceProperty(): ?string
    {
        if (! $this->selected_plan) {
            return null;
        }

        $plan = collect($this->plans)->firstWhere('id', $this->selected_plan);
        $monthlyCost = $plan['monthly_cost'] ?? null;

        if ($monthlyCost === null) {
            return null;
        }

        return '$'.number_format((float) $monthlyCost, 2);
    }

    public function getAdvancedVultrOptionsSummaryProperty(): array
    {
        $summary = [];

        if (count($this->selectedVultrSshKeyIds) > 0) {
            $summary[] = count($this->selectedVultrSshKeyIds).' extra SSH '.str('key')->plural(count($this->selectedVultrSshKeyIds));
        }

        if (! $this->enable_ipv6) {
            $summary[] = 'IPv6 disabled';
        }

        if ($this->disable_public_ipv4) {
            $summary[] = 'Public IPv4 disabled';
        }

        if (! empty($this->cloud_init_script)) {
            $summary[] = 'cloud-init';
        }

        return $summary;
    }

    private function getVultrToken(): string
    {
        if ($this->selected_token_id) {
            $token = $this->available_tokens->firstWhere('id', $this->selected_token_id);

            return $token ? $token->token : '';
        }

        return '';
    }

    public function loadVultrData(): void
    {
        $token = $this->getVultrToken();

        if (! $token) {
            $this->loading_data = false;
            $this->dispatch('error', 'Please select a valid Vultr token.');

            return;
        }

        $this->loading_data = true;
        $this->provider_data_error = null;

        try {
            $vultrService = new VultrService($token);

            $this->regions = collect($vultrService->getRegions())
                ->sortBy('id')
                ->values()
                ->toArray();

            $this->plans = collect($vultrService->getPlans())
                ->sortBy('monthly_cost')
                ->values()
                ->toArray();

            $this->operatingSystems = collect($vultrService->getOperatingSystems())
                ->sortBy('name')
                ->values()
                ->toArray();

            $this->vultrSshKeys = $vultrService->getSshKeys();
            $this->loading_data = false;
        } catch (\Throwable $e) {
            $this->loading_data = false;
            $this->provider_data_error = $this->providerDataErrorMessage('Vultr', $e, 'error');
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

    private function createVultrServer(string $token): array
    {
        $vultrService = new VultrService($token);
        $privateKey = PrivateKey::ownedByCurrentTeam()->findOrFail($this->private_key_id);
        $publicKey = $privateKey->getPublicKey();
        $existingKey = $this->findMatchingSshKey($vultrService->getSshKeys(), $publicKey);

        if ($existingKey) {
            $sshKeyId = $existingKey['id'];
        } else {
            $uploadedKey = $vultrService->uploadSshKey($privateKey->name, $publicKey);
            $sshKeyId = $uploadedKey['id'];
        }

        $sshKeys = array_values(array_unique(array_merge([$sshKeyId], $this->selectedVultrSshKeyIds)));
        $normalizedServerName = strtolower(trim($this->server_name));

        $params = [
            'region' => $this->selected_region,
            'plan' => $this->selected_plan,
            'os_id' => $this->selected_os_id,
            'label' => $normalizedServerName,
            'hostname' => $normalizedServerName,
            'sshkey_id' => $sshKeys,
            'enable_ipv6' => $this->enable_ipv6,
            'disable_public_ipv4' => $this->disable_public_ipv4,
        ];

        if (! empty($this->cloud_init_script)) {
            $params['user_data'] = $this->cloud_init_script;
        }

        return $vultrService->createInstance($params);
    }

    public function submit(): mixed
    {
        $this->validate();
        if (! $this->hasValidPublicNetworkConfiguration()) {
            return null;
        }

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

            $vultrService = new VultrService($this->getVultrToken());
            $vultrInstance = $this->createVultrServer($this->getVultrToken());
            $ipAddress = $vultrService->getPublicIp($vultrInstance, $this->disable_public_ipv4, $this->enable_ipv6) ?? Server::PLACEHOLDER_IP;

            $server = Server::create([
                'name' => strtolower(trim($this->server_name)),
                'ip' => $ipAddress,
                'user' => 'root',
                'port' => 22,
                'team_id' => currentTeam()->id,
                'private_key_id' => $this->private_key_id,
                'cloud_provider_token_id' => $this->selected_token_id,
                'vultr_instance_id' => $vultrInstance['id'],
                'vultr_instance_status' => $vultrInstance['status'] ?? null,
            ]);

            try {
                $vultrInstance = $vultrService->waitForPublicIp($vultrInstance, $this->disable_public_ipv4, $this->enable_ipv6);
                $assignedIpAddress = $vultrService->getPublicIp($vultrInstance, $this->disable_public_ipv4, $this->enable_ipv6);
                if ($assignedIpAddress && $assignedIpAddress !== $server->ip) {
                    $server->update([
                        'ip' => $assignedIpAddress,
                        'vultr_instance_status' => $vultrInstance['status'] ?? $server->vultr_instance_status,
                    ]);
                }
            } catch (\Throwable $e) {
                // Non-fatal: the server page polling backfills the IP later.
                report($e);
            }

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
        return view('livewire.server.new.by-vultr');
    }

    private function findMatchingSshKey(array $sshKeys, string $publicKey): ?array
    {
        $normalizedPublicKey = $this->normalizePublicKey($publicKey);

        foreach ($sshKeys as $sshKey) {
            if ($this->normalizePublicKey($sshKey['ssh_key'] ?? '') === $normalizedPublicKey) {
                return $sshKey;
            }
        }

        return null;
    }

    private function normalizePublicKey(string $publicKey): string
    {
        $parts = preg_split('/\s+/', trim($publicKey));

        return implode(' ', array_slice($parts ?: [], 0, 2));
    }

    private function hasValidPublicNetworkConfiguration(): bool
    {
        if (! $this->disable_public_ipv4 || $this->enable_ipv6) {
            return true;
        }

        $this->addError('enable_ipv6', 'Enable IPv6 when disabling public IPv4.');

        return false;
    }
}
