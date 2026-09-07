<?php

namespace App\Livewire\Server;

use App\Models\CloudProviderToken;
use App\Models\Server;
use App\Services\CloudflareDnsService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Validate;
use Livewire\Component;

class Advanced extends Component
{
    use AuthorizesRequests;

    public Server $server;

    public array $parameters = [];

    #[Validate(['string'])]
    public string $serverDiskUsageCheckFrequency = '0 23 * * *';

    #[Validate(['required', 'integer', 'min:1', 'max:99'])]
    public int|string $serverDiskUsageNotificationThreshold = 50;

    #[Validate(['required', 'integer', 'min:1'])]
    public int|string $concurrentBuilds = 1;

    #[Validate(['required', 'integer', 'min:1'])]
    public int|string $dynamicTimeout = 1;

    #[Validate(['required', 'integer', 'min:1'])]
    public int|string $deploymentQueueLimit = 25;

    public bool $cloudflareDnsEnabled = false;

    public int|string|null $cloudflareDnsTokenId = null;

    public bool $cloudflareDnsProxied = false;

    public $cloudflareTokens = [];

    public function mount(string $server_uuid)
    {
        try {
            $this->server = Server::ownedByCurrentTeam()->whereUuid($server_uuid)->firstOrFail();
            $this->parameters = get_route_parameters();
            $this->loadCloudflareTokens();
            $this->syncData();

        } catch (\Throwable) {
            return redirect()->route('server.index');
        }
    }

    public function syncData(bool $toModel = false)
    {
        if ($toModel) {
            $this->authorize('update', $this->server);
            $this->validate();
            $this->validateCloudflareDnsSettings();
            $this->server->settings->concurrent_builds = $this->concurrentBuilds;
            $this->server->settings->dynamic_timeout = $this->dynamicTimeout;
            $this->server->settings->deployment_queue_limit = $this->deploymentQueueLimit;
            $this->server->settings->server_disk_usage_notification_threshold = $this->serverDiskUsageNotificationThreshold;
            $this->server->settings->server_disk_usage_check_frequency = $this->serverDiskUsageCheckFrequency;
            $this->server->settings->cloudflare_dns_enabled = $this->cloudflareDnsEnabled;
            $this->server->settings->cloudflare_dns_token_id = $this->cloudflareDnsEnabled ? $this->cloudflareDnsTokenId : null;
            $this->server->settings->cloudflare_dns_proxied = $this->cloudflareDnsEnabled && $this->cloudflareDnsProxied;
            $this->server->settings->save();
            $this->server->refresh();
        } else {
            $this->concurrentBuilds = $this->server->settings->concurrent_builds;
            $this->dynamicTimeout = $this->server->settings->dynamic_timeout;
            $this->deploymentQueueLimit = $this->server->settings->deployment_queue_limit;
            $this->serverDiskUsageNotificationThreshold = $this->server->settings->server_disk_usage_notification_threshold;
            $this->serverDiskUsageCheckFrequency = $this->server->settings->server_disk_usage_check_frequency;
            $this->cloudflareDnsEnabled = (bool) $this->server->settings->cloudflare_dns_enabled;
            $this->cloudflareDnsTokenId = $this->server->settings->cloudflare_dns_token_id;
            $this->cloudflareDnsProxied = (bool) $this->server->settings->cloudflare_dns_proxied;
        }
    }

    public function loadCloudflareTokens(): void
    {
        $this->cloudflareTokens = CloudProviderToken::ownedByCurrentTeam()
            ->where('provider', 'cloudflare')
            ->get();
    }

    public function checkCloudflareDns(): void
    {
        try {
            $this->syncData(true);

            $service = CloudflareDnsService::forServer($this->server);
            if (! $service) {
                $this->dispatch('error', 'Cloudflare DNS is not configured for this server.');

                return;
            }

            $results = collect();
            foreach ($this->server->applications() as $application) {
                $domains = $service->domainsForApplication($application);
                if (empty($domains)) {
                    continue;
                }

                $results = $results->concat($service->ensureRecordsForDomains(
                    server: $this->server,
                    domains: $domains,
                    proxied: $this->cloudflareDnsProxied,
                ));
            }

            if ($results->isEmpty()) {
                $this->dispatch('warning', 'No application domains found on this server.');

                return;
            }

            $this->dispatch('success', "Cloudflare DNS checked for {$results->count()} domain(s).");
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
    }

    private function validateCloudflareDnsSettings(): void
    {
        if (! $this->cloudflareDnsEnabled) {
            return;
        }

        if (blank($this->cloudflareDnsTokenId)) {
            throw new \Exception('Please select a Cloudflare integration token.');
        }

        $token = CloudProviderToken::ownedByCurrentTeam()
            ->where('provider', 'cloudflare')
            ->whereKey($this->cloudflareDnsTokenId)
            ->first();

        if (! $token) {
            throw new \Exception('Selected Cloudflare token is invalid.');
        }
    }

    public function instantSave()
    {
        try {
            $this->syncData(true);
            $this->dispatch('success', 'Server updated.');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function submit()
    {
        try {
            if (! validate_cron_expression($this->serverDiskUsageCheckFrequency)) {
                $this->serverDiskUsageCheckFrequency = $this->server->settings->getOriginal('server_disk_usage_check_frequency');
                throw new \Exception('Invalid Cron / Human expression for Disk Usage Check Frequency.');
            }
            $this->syncData(true);
            $this->dispatch('success', 'Server updated.');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function render()
    {
        return view('livewire.server.advanced');
    }
}
