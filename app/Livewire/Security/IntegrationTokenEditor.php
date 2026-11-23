<?php

namespace App\Livewire\Security;

use App\Actions\IntegrationToken\UpdateIntegrationToken;
use App\Models\IntegrationToken;
use App\Services\Dns\CloudflareDnsProvider;
use App\Services\IntegrationTokenValidator;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

class IntegrationTokenEditor extends Component
{
    use AuthorizesRequests;

    public IntegrationToken $integrationToken;

    public string $name = '';

    public string $newToken = '';

    public array $capabilities = [];

    public array $metadata = [];

    public int $zoneCount = 0;

    /** @var array<int, array{id: int, name: string, account_name: ?string, managed_records_count: int}> */
    public array $zones = [];

    public bool $automaticDns = true;

    public bool $proxiedDns = false;

    public function mount(string $integration_token_uuid): void
    {
        $this->integrationToken = IntegrationToken::ownedByCurrentTeam()
            ->whereUuid($integration_token_uuid)
            ->firstOrFail();

        $this->authorize('view', $this->integrationToken);

        $this->name = $this->integrationToken->name;
        $this->capabilities = $this->integrationToken->capabilities;
        $this->metadata = $this->integrationToken->metadata ?? [];
        $this->loadZones();
        $this->automaticDns = $this->integrationToken->automaticDnsEnabled();
        $this->proxiedDns = $this->integrationToken->proxiedDnsEnabled();
    }

    /**
     * Cloudflare Tunnel setup also writes DNS records, so selecting `tunnel` also selects `dns`.
     */
    public function updatedCapabilities(): void
    {
        if (in_array('tunnel', $this->capabilities, true) && ! in_array('dns', $this->capabilities, true)) {
            $this->capabilities[] = 'dns';
        }
    }

    protected function rules(): array
    {
        $rules = UpdateIntegrationToken::rules($this->integrationToken->provider);
        $rules['newToken'] = $rules['token'];
        unset($rules['name'], $rules['token']);

        return ['name' => ['required', 'string', 'max:255']] + $rules + [
            'automaticDns' => ['boolean'],
            'proxiedDns' => ['boolean'],
        ];
    }

    protected function messages(): array
    {
        return [
            'capabilities.required' => 'Select at least one capability.',
            'capabilities.min' => 'Select at least one capability.',
        ];
    }

    public function save(IntegrationTokenValidator $validator): void
    {
        $this->authorize('update', $this->integrationToken);
        $validated = $this->validate();

        try {
            UpdateIntegrationToken::run(
                $this->integrationToken,
                $validated['name'],
                $validated['newToken'],
                $validated['capabilities'],
                data_get($validated, 'metadata', []),
                $validated['automaticDns'],
                $validated['proxiedDns'],
                'ui',
            );
        } catch (ValidationException $e) {
            $this->addError('newToken', $e->validator->errors()->first('token'));

            return;
        } catch (ConnectionException) {
            $this->dispatch('error', $validator->connectionErrorMessage($this->integrationToken->provider));

            return;
        } catch (\Throwable $e) {
            handleError($e, $this);

            return;
        }

        $this->newToken = '';
        $this->loadZones();
        $this->dispatch(
            'integration-token-updated',
            uuid: $this->integrationToken->uuid,
            name: $this->integrationToken->name,
            capabilities: $this->integrationToken->capabilities,
        );
        $this->dispatch('success', 'Integration token updated successfully.');
    }

    public function delete(string $password = ''): void
    {
        $this->authorize('delete', $this->integrationToken);

        if ($blocker = $this->integrationToken->deletionBlocker()) {
            $this->dispatch('error', $blocker);

            return;
        }

        $uuid = $this->integrationToken->uuid;
        $name = $this->integrationToken->name;
        $provider = $this->integrationToken->provider;
        $this->integrationToken->delete();

        auditLog('ui.integration_token.deleted', [
            'team_id' => $this->integrationToken->team_id,
            'integration_token_uuid' => $uuid,
            'integration_token_name' => $name,
            'provider' => $provider,
        ]);

        $this->dispatch('integration-token-deleted', uuid: $this->integrationToken->uuid);
        $this->dispatch('close-modal');
        $this->dispatch('success', 'Integration token deleted successfully.');
    }

    public function refreshZones(CloudflareDnsProvider $cloudflare): void
    {
        $this->authorize('update', $this->integrationToken);
        try {
            $cloudflare->syncZones($this->integrationToken);
            $this->integrationToken->refresh();
            $this->loadZones();
            $this->dispatch('success', "Cloudflare zones refreshed. {$this->zoneCount} accessible zones found.");
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
    }

    public function render()
    {
        return view('livewire.security.integration-token-editor');
    }

    private function loadZones(): void
    {
        $this->zones = $this->integrationToken->dnsZones()
            ->select(['id', 'integration_token_id', 'name', 'account_name'])
            ->withCount('managedRecords')
            ->orderBy('name')
            ->get()
            ->map(fn ($zone) => [
                'id' => $zone->id,
                'name' => $zone->name,
                'account_name' => $zone->account_name,
                'managed_records_count' => $zone->managed_records_count,
            ])
            ->all();
        $this->zoneCount = count($this->zones);
    }
}
