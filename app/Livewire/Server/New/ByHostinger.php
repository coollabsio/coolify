<?php

namespace App\Livewire\Server\New;

use App\Enums\ProxyTypes;
use App\Models\CloudProviderToken;
use App\Models\PrivateKey;
use App\Models\Server;
use App\Models\Team;
use App\Rules\ValidHostname;
use App\Services\HostingerService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Collection;
use Livewire\Attributes\Locked;
use Livewire\Component;

class ByHostinger extends Component
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

    public array $data_centers = [];

    public array $templates = [];

    public array $catalog_items = [];

    public ?int $selected_data_center_id = null;

    public ?int $selected_template_id = null;

    public ?string $selected_price_id = null;

    public string $server_name = '';

    public ?int $private_key_id = null;

    public bool $enable_backups = true;

    public bool $loading_data = false;

    public ?string $provider_data_error = null;

    public bool $from_onboarding = false;

    public function mount(?string $selectedTokenUuid = null): mixed
    {
        try {
            $this->authorize('viewAny', CloudProviderToken::class);
            $this->loadTokens();
            $this->selectTokenFromUrl($selectedTokenUuid);
            $this->server_name = generate_random_name();
            $this->private_keys = PrivateKey::ownedAndOnlySShKeys()->where('id', '!=', 0)->get();

            if ($this->private_keys->isNotEmpty()) {
                $this->private_key_id = $this->private_keys->first()->id;
            }

            if ($this->selectedTokenUuid) {
                $this->current_step = 2;
                $this->loading_data = true;
            }

            return null;
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function getListeners(): array
    {
        return [
            'tokenAdded.hostinger' => 'handleTokenAdded',
            'privateKeyCreated' => 'handlePrivateKeyCreated',
            'modalClosed' => 'resetSelection',
        ];
    }

    protected function rules(): array
    {
        $rules = [
            'selected_token_id' => 'required|integer|exists:cloud_provider_tokens,id',
        ];

        if ($this->current_step === 2) {
            $rules = array_merge($rules, [
                'server_name' => ['required', 'string', 'max:253', new ValidHostname],
                'selected_data_center_id' => 'required|integer',
                'selected_template_id' => 'required|integer',
                'selected_price_id' => 'required|string',
                'private_key_id' => 'required|integer|exists:private_keys,id,team_id,'.currentTeam()->id,
                'enable_backups' => 'required|boolean',
            ]);
        }

        return $rules;
    }

    protected function messages(): array
    {
        return [
            'selected_token_id.required' => 'Please select a Hostinger token.',
            'selected_token_id.exists' => 'Selected token not found.',
        ];
    }

    public function loadTokens(): void
    {
        $this->available_tokens = CloudProviderToken::ownedByCurrentTeam()
            ->where('provider', 'hostinger')
            ->get();
    }

    public function handleTokenAdded(int $tokenId): mixed
    {
        $this->loadTokens();
        $this->selected_token_id = $tokenId;

        return $this->nextStep();
    }

    public function handlePrivateKeyCreated(int $keyId): void
    {
        $this->private_keys = PrivateKey::ownedAndOnlySShKeys()->where('id', '!=', 0)->get();
        $this->private_key_id = $keyId;
        $this->resetErrorBag('private_key_id');
    }

    public function resetSelection(): void
    {
        $this->selected_token_id = null;
        $this->current_step = 1;
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
                        'type' => 'hostinger',
                        'token_uuid' => $token->uuid,
                    ], navigate: true);
                }
            }

            $this->current_step = 2;
            $this->loading_data = true;

            return null;
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function loadHostingerData(): void
    {
        $token = $this->getHostingerToken();

        if (! $token) {
            $this->loading_data = false;
            $this->dispatch('error', 'Please select a valid Hostinger token.');

            return;
        }

        $this->loading_data = true;
        $this->provider_data_error = null;

        try {
            $hostingerService = new HostingerService($token);
            $this->data_centers = $hostingerService->getDataCenters();
            $this->templates = collect($hostingerService->getTemplates())
                ->sortBy('name')
                ->values()
                ->toArray();
            $this->catalog_items = $hostingerService->getCatalogItems();
        } catch (\Throwable $e) {
            $this->provider_data_error = $e->getMessage();
            $this->dispatch('error', $this->provider_data_error);
        } finally {
            $this->loading_data = false;
        }
    }

    public function getPriceOptionsProperty(): array
    {
        return collect($this->catalog_items)
            ->flatMap(function (array $item): array {
                return collect($item['prices'] ?? [])
                    ->map(fn (array $price): array => array_merge($price, [
                        'plan_name' => $item['name'] ?? $item['id'] ?? 'VPS',
                    ]))
                    ->all();
            })
            ->sortBy(fn (array $price) => ($price['plan_name'] ?? '').str_pad((string) ($price['period'] ?? 0), 4, '0', STR_PAD_LEFT))
            ->values()
            ->toArray();
    }

    public function getSelectedPriceProperty(): ?array
    {
        return collect($this->priceOptions)->firstWhere('id', $this->selected_price_id);
    }

    public function priceLabel(array $price): string
    {
        $currency = strtoupper($price['currency'] ?? 'USD');
        $firstPeriodPrice = (int) ($price['first_period_price'] ?? $price['price'] ?? 0);
        $renewalPrice = (int) ($price['price'] ?? $firstPeriodPrice);
        $period = (int) ($price['period'] ?? 1);
        $periodUnit = $price['period_unit'] ?? 'month';
        $periodLabel = $period.' '.str($periodUnit)->plural($period);

        return sprintf(
            '%s — %s %.2f for %s, then %s %.2f',
            $price['plan_name'] ?? 'VPS',
            $currency,
            $firstPeriodPrice / 100,
            $periodLabel,
            $currency,
            $renewalPrice / 100
        );
    }

    public function submit(): mixed
    {
        $this->validate();

        try {
            $this->authorize('create', Server::class);

            if (Team::serverLimitReached()) {
                return $this->dispatch('error', 'You have reached the server limit for your subscription.');
            }

            $privateKey = PrivateKey::ownedByCurrentTeam()->findOrFail($this->private_key_id);
            $hostingerService = new HostingerService($this->getHostingerToken());

            if (! $this->providerSelectionsAreValid($hostingerService)) {
                return null;
            }

            $normalizedServerName = strtolower(trim($this->server_name));
            $virtualMachine = $hostingerService->purchaseVirtualMachine([
                'item_id' => $this->selected_price_id,
                'setup' => [
                    'data_center_id' => $this->selected_data_center_id,
                    'template_id' => $this->selected_template_id,
                    'hostname' => $normalizedServerName,
                    'enable_backups' => $this->enable_backups,
                    'public_key' => [
                        'name' => $privateKey->name,
                        'key' => $privateKey->getPublicKey(),
                    ],
                ],
            ]);
            $virtualMachine = $hostingerService->waitForPublicIp($virtualMachine);
            $ipAddress = $hostingerService->getPublicIpAddress($virtualMachine);

            if (! $ipAddress) {
                throw new \Exception('No public IP address available for the new Hostinger VPS. Complete setup in hPanel, then link it to Coolify manually.');
            }

            $server = Server::create([
                'name' => $normalizedServerName,
                'ip' => $ipAddress,
                'user' => 'root',
                'port' => 22,
                'team_id' => currentTeam()->id,
                'private_key_id' => $privateKey->id,
                'cloud_provider_token_id' => $this->selected_token_id,
                'hostinger_virtual_machine_id' => $virtualMachine['id'],
                'hostinger_virtual_machine_status' => $virtualMachine['state'] ?? null,
            ]);

            $server->proxy->set('status', 'exited');
            $server->proxy->set('type', ProxyTypes::TRAEFIK->value);
            $server->save();

            if ($this->from_onboarding) {
                currentTeam()->update(['show_boarding' => false]);
                refreshSession();
            }

            return redirectRoute($this, 'server.show', [$server->uuid]);
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    private function selectTokenFromUrl(?string $selectedTokenUuid): void
    {
        if (! $selectedTokenUuid) {
            return;
        }

        $token = $this->available_tokens->firstWhere('uuid', $selectedTokenUuid);

        if ($token) {
            $this->selectedTokenUuid = $selectedTokenUuid;
            $this->selected_token_id = $token->id;
        }
    }

    private function getHostingerToken(): string
    {
        $token = $this->available_tokens->firstWhere('id', $this->selected_token_id);

        return $token?->token ?? '';
    }

    private function providerSelectionsAreValid(HostingerService $hostingerService): bool
    {
        $priceIds = collect($hostingerService->getCatalogItems())
            ->flatMap(fn (array $item) => collect($item['prices'] ?? [])->pluck('id'));
        $dataCenterIds = collect($hostingerService->getDataCenters())->pluck('id')->map(fn ($id) => (int) $id);
        $templateIds = collect($hostingerService->getTemplates())->pluck('id')->map(fn ($id) => (int) $id);

        if (! $priceIds->contains($this->selected_price_id)) {
            $this->addError('selected_price_id', 'The selected Hostinger plan or billing period is no longer available.');
        }

        if (! $dataCenterIds->contains($this->selected_data_center_id)) {
            $this->addError('selected_data_center_id', 'The selected Hostinger data center is no longer available.');
        }

        if (! $templateIds->contains($this->selected_template_id)) {
            $this->addError('selected_template_id', 'The selected Hostinger operating system is no longer available.');
        }

        return ! $this->getErrorBag()->isNotEmpty();
    }

    public function render()
    {
        return view('livewire.server.new.by-hostinger');
    }
}
