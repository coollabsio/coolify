<?php

namespace App\Livewire\Settings;

use App\Actions\C3\SyncGlobalProxyConfig;
use App\Models\InstanceSettings;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Validate;
use Livewire\Component;

/**
 * Connect3 fork: instance-wide staging apex + Cloudflare DNS-01 token (PRD 7.2).
 */
class C3 extends Component
{
    use AuthorizesRequests;

    public InstanceSettings $settings;

    #[Validate(['nullable', 'string', 'max:253', 'regex:/^[a-z0-9.-]+$/i'])]
    public ?string $c3_staging_apex = null;

    #[Validate(['nullable', 'string', 'max:255'])]
    public ?string $c3_cloudflare_dns_token = null;

    public function mount(): void
    {
        if (! isInstanceAdmin()) {
            abort(403);
        }
        $this->settings = instanceSettings();
        $this->c3_staging_apex = $this->settings->c3_staging_apex;
        $this->c3_cloudflare_dns_token = $this->settings->c3_cloudflare_dns_token;
    }

    public function submit(): void
    {
        try {
            $this->authorize('update', $this->settings);
            $this->c3_staging_apex = c3_normalizeApex($this->c3_staging_apex);
            $this->validate();

            $tokenChanged = ($this->c3_cloudflare_dns_token ?: null) !== ($this->settings->c3_cloudflare_dns_token ?: null);
            $this->settings->c3_staging_apex = $this->c3_staging_apex;
            $this->settings->c3_cloudflare_dns_token = $this->c3_cloudflare_dns_token ?: null;
            $this->settings->save();

            SyncGlobalProxyConfig::run();

            $this->dispatch('success', 'Connect3 settings saved.');
            if ($tokenChanged) {
                $this->dispatch('info', 'Restart the proxy so Traefik picks up the DNS-01 wildcard resolver.');
            }
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
    }

    public function render()
    {
        return view('livewire.settings.c3');
    }
}
