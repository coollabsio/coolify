<?php

namespace App\Livewire\Security;

use App\Models\IntegrationToken;
use App\Services\Dns\CloudflareDnsProvider;
use App\Services\IntegrationTokenValidator;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\DB;
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
    }

    protected function rules(): array
    {
        $allowedCapability = $this->integrationToken->provider === 'cloudflare' ? 'dns' : 'secrets';

        $rules = [
            'name' => ['required', 'string', 'max:255'],
            'newToken' => ['nullable', 'string'],
            'capabilities' => ['required', 'array', 'min:1'],
            'capabilities.*' => ['required', 'in:'.$allowedCapability],
            'automaticDns' => ['boolean'],
        ];

        if ($this->integrationToken->provider === 'infisical') {
            $rules['metadata.base_url'] = ['required', 'url:http,https'];
            $rules['metadata.client_id'] = ['required', 'string'];
        }

        if ($this->integrationToken->provider === 'vault') {
            $rules['metadata.base_url'] = ['required', 'url:http,https'];
            $rules['metadata.namespace'] = ['nullable', 'string'];
        }

        return $rules;
    }

    protected function messages(): array
    {
        return [
            'capabilities.required' => 'Select at least one capability.',
            'capabilities.min' => 'Select at least one capability.',
        ];
    }

    public function save(IntegrationTokenValidator $validator, CloudflareDnsProvider $cloudflare): void
    {
        $this->authorize('update', $this->integrationToken);
        $validated = $this->validate();
        $provider = $this->integrationToken->provider;
        $token = filled($validated['newToken']) ? $validated['newToken'] : $this->integrationToken->token;
        $metadata = array_filter(data_get($validated, 'metadata', []), fn ($value) => filled($value));
        if ($provider === 'cloudflare') {
            if ($validated['automaticDns']) {
                unset($metadata['automatic_dns']);
            } else {
                $metadata['automatic_dns'] = false;
            }
        }
        $storedMetadata = $this->integrationToken->metadata ?? [];
        $changedConnectionFields = $this->changedConnectionFields($provider, $storedMetadata, $metadata);
        if ($changedConnectionFields !== [] && blank($validated['newToken'])) {
            $this->addError('newToken', $this->reenterSecretMessage($provider));

            return;
        }
        $capabilitiesChanged = collect($validated['capabilities'])->sort()->values()->all()
            !== collect($this->integrationToken->capabilities)->sort()->values()->all();
        $metadataChanged = $metadata != $storedMetadata;

        try {
            if ((filled($validated['newToken']) || $capabilitiesChanged || $metadataChanged)
                && ! $validator->validate($provider, $token, $validated['capabilities'], $metadata)) {
                $this->dispatch('error', $validator->errorMessage($provider));

                return;
            }

            $updates = [
                'name' => $validated['name'],
                'capabilities' => $validated['capabilities'],
                'metadata' => $metadata ?: null,
            ];

            if (filled($validated['newToken'])) {
                $updates['token'] = $validated['newToken'];
            }

            DB::transaction(function () use ($updates, $provider, $validated, $capabilitiesChanged, $cloudflare): void {
                $this->integrationToken->update($updates);
                if ($provider === 'cloudflare' && (filled($validated['newToken']) || $capabilitiesChanged)) {
                    $cloudflare->syncZones($this->integrationToken);
                }
            });
            $this->newToken = '';
            $this->loadZones();

            auditLog('ui.integration_token.updated', [
                'team_id' => $this->integrationToken->team_id,
                'integration_token_uuid' => $this->integrationToken->uuid,
                'integration_token_name' => $this->integrationToken->name,
                'provider' => $this->integrationToken->provider,
                'rotated' => array_key_exists('token', $updates),
                'connection_changed_fields' => $changedConnectionFields,
            ]);

            if (in_array('base_url', $changedConnectionFields, true)) {
                auditLog('ui.integration_token.base_url_changed', [
                    'team_id' => $this->integrationToken->team_id,
                    'integration_token_uuid' => $this->integrationToken->uuid,
                    'integration_token_name' => $this->integrationToken->name,
                    'provider' => $provider,
                    'previous_base_url' => $this->baseUrlForAudit(data_get($storedMetadata, 'base_url')),
                    'base_url' => $this->baseUrlForAudit(data_get($metadata, 'base_url')),
                ], 'warning');
            }

            $this->dispatch(
                'integration-token-updated',
                uuid: $this->integrationToken->uuid,
                name: $this->integrationToken->name,
                capabilities: $this->integrationToken->capabilities,
            );
            $this->dispatch('success', 'Integration token updated successfully.');
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
    }

    /**
     * Metadata keys that decide where or how the stored secret is sent.
     * Changing any of them must require the secret to be entered again.
     *
     * @return array<int, string>
     */
    private function connectionFields(string $provider): array
    {
        return match ($provider) {
            'vault' => ['base_url', 'namespace'],
            'infisical' => ['base_url', 'client_id'],
            default => [],
        };
    }

    /**
     * @param  array<string, mixed>  $storedMetadata
     * @param  array<string, mixed>  $metadata
     * @return array<int, string>
     */
    private function changedConnectionFields(string $provider, array $storedMetadata, array $metadata): array
    {
        return array_values(array_filter(
            $this->connectionFields($provider),
            fn (string $field): bool => (string) data_get($storedMetadata, $field, '') !== (string) data_get($metadata, $field, ''),
        ));
    }

    /**
     * Base URL without user info, query, or fragment, which can carry credentials.
     */
    private function baseUrlForAudit(mixed $url): ?string
    {
        if (! is_string($url) || $url === '') {
            return null;
        }

        $parts = parse_url($url);
        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            return '[invalid-url]';
        }

        return $parts['scheme'].'://'.$parts['host']
            .(isset($parts['port']) ? ':'.$parts['port'] : '')
            .($parts['path'] ?? '');
    }

    private function reenterSecretMessage(string $provider): string
    {
        return match ($provider) {
            'infisical' => 'Enter the client secret again when you change the base URL or the client ID.',
            'vault' => 'Enter the token again when you change the base URL or the namespace.',
            default => 'Enter the token again when you change the connection settings.',
        };
    }

    public function delete(string $password = ''): void
    {
        $this->authorize('delete', $this->integrationToken);

        if ($this->integrationToken->secretManagerLinks()->exists()) {
            $this->dispatch('error', 'This token is used by one or more resources as a secret manager source. Remove those links first.');

            return;
        }

        if ($this->integrationToken->managedDnsRecords()->exists()) {
            $this->dispatch('error', 'This token manages DNS records. Remove those domains or records first.');

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
