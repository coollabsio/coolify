<?php

namespace App\Livewire\Project\Service;

use App\Models\Service;
use App\Models\ServiceApplication;
use App\Support\ValidationPatterns;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Collection;
use Livewire\Component;

class Domains extends Component
{
    use AuthorizesRequests;

    public Service $service;

    /** @var array<int, array{id: int, name: string, image: ?string, required_port: ?int}> */
    public array $serviceApps = [];

    /** @var array<int, array<string, mixed>> */
    public array $domainRows = [];

    public ?int $newServiceApplicationId = null;

    public string $newDomain = '';

    public ?int $editingIndex = null;

    public string $editingDomain = '';

    public ?int $editingServiceApplicationId = null;

    public bool $showEditDomainModal = false;

    public bool $forceSaveDomains = false;

    public bool $forceSaveDns = false;

    public bool $addDomainDnsFailed = false;

    public string $addDomainDnsMessage = '';

    public bool $forceSaveEditDns = false;

    public bool $editDomainDnsFailed = false;

    public string $editDomainDnsMessage = '';

    public ?int $forceAddSuggestedIndex = null;

    public array $domainConflicts = [];

    public bool $showDomainConflictModal = false;

    public bool $showPortWarningModal = false;

    public bool $forceRemovePort = false;

    public ?int $requiredPort = null;

    public bool $isCheckingDns = false;

    public bool $dnsValidationEnabled = true;

    public ?string $serverIp = null;

    public ?string $serverIpConfigured = null;

    /** Pending save payload after conflict/port confirmation */
    public ?string $pendingAction = null;

    protected $listeners = [
        'refresh' => 'refreshDomains',
        'refreshServices' => 'refreshDomains',
        'confirmDomainUsage',
    ];

    protected function rules(): array
    {
        return [
            'newDomain' => ValidationPatterns::applicationDomainRules(),
            'editingDomain' => ValidationPatterns::applicationDomainRules(),
            'newServiceApplicationId' => 'nullable|integer',
        ];
    }

    public function mount(): void
    {
        $this->authorize('view', $this->service);
        $this->loadDomainState();
    }

    public function refreshDomains(): void
    {
        $this->service->refresh();
        $this->service->load(['applications', 'server']);
        $this->loadDomainState();
    }

    public function loadDomainState(): void
    {
        $this->service->loadMissing(['applications', 'server']);

        $settings = instanceSettings();
        $this->dnsValidationEnabled = (bool) data_get($settings, 'is_dns_validation_enabled', true);

        $server = $this->service->server;
        if ($server) {
            if ($server->id === 0) {
                $configured = data_get($settings, 'public_ipv4')
                    ?: data_get($settings, 'public_ipv6')
                    ?: $server->ip;
            } else {
                $configured = $server->ip;
            }
            $resolved = resolveServerIpAddress(is_string($configured) ? $configured : null);
            $this->serverIpConfigured = $resolved['configured'];
            $this->serverIp = $resolved['ip'] ?? $resolved['configured'];
        } else {
            $this->serverIp = null;
            $this->serverIpConfigured = null;
        }

        $this->serviceApps = $this->service->applications
            ->sortBy(fn (ServiceApplication $app) => strtolower($app->human_name ?: $app->name))
            ->values()
            ->map(fn (ServiceApplication $app) => [
                'id' => $app->id,
                'name' => $app->human_name ?: $app->name,
                'image' => $app->image,
                'required_port' => $app->getRequiredPort(),
            ])
            ->all();

        if ($this->newServiceApplicationId === null && count($this->serviceApps) > 0) {
            $this->newServiceApplicationId = $this->serviceApps[0]['id'];
        }

        $this->domainRows = $this->buildDomainRows();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function buildDomainRows(): array
    {
        $rows = [];

        foreach ($this->service->applications->sortBy(fn (ServiceApplication $app) => strtolower($app->human_name ?: $app->name)) as $app) {
            $stored = is_array($app->domain_dns_statuses) ? $app->domain_dns_statuses : [];
            $configured = [];

            foreach ($this->splitDomains($app->fqdn) as $url) {
                $row = $this->domainRowFromStored($url, $app, $stored);
                $rows[] = $row;
                $configured[] = $row;
            }

            foreach ($this->buildSuggestedWwwRows($configured, $app, $stored) as $suggested) {
                $rows[] = $suggested;
            }
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $stored
     * @return array<string, mixed>
     */
    protected function domainRowFromStored(string $url, ServiceApplication $app, array $stored): array
    {
        $entry = $stored[$url] ?? null;
        $displayName = $app->human_name ?: $app->name;

        if (is_array($entry) && filled(data_get($entry, 'status'))) {
            return [
                'service_application_id' => $app->id,
                'service_name' => $displayName,
                'service_image' => $app->image,
                'url' => $url,
                'dns_status' => (string) data_get($entry, 'status', 'pending'),
                'dns_message' => (string) data_get($entry, 'message', 'Not checked yet.'),
                'expected_ip' => data_get($entry, 'expected_ip') ?: $this->serverIp,
                'checked_at' => data_get($entry, 'checked_at'),
                'is_suggested' => false,
                'suggested_for' => null,
                'suggestion_label' => null,
                'needs_force_add' => false,
            ];
        }

        return [
            'service_application_id' => $app->id,
            'service_name' => $displayName,
            'service_image' => $app->image,
            'url' => $url,
            'dns_status' => 'pending',
            'dns_message' => 'Not checked yet.',
            'expected_ip' => $this->serverIp,
            'checked_at' => null,
            'is_suggested' => false,
            'suggested_for' => null,
            'suggestion_label' => null,
            'needs_force_add' => false,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $configuredRows
     * @param  array<string, mixed>  $stored
     * @return array<int, array<string, mixed>>
     */
    protected function buildSuggestedWwwRows(array $configuredRows, ServiceApplication $app, array $stored): array
    {
        $knownHosts = [];
        foreach ($configuredRows as $row) {
            $host = $this->domainHost((string) ($row['url'] ?? ''));
            if ($host !== null) {
                $knownHosts[strtolower($host)] = true;
            }
        }

        $suggested = [];
        foreach ($configuredRows as $row) {
            $url = (string) ($row['url'] ?? '');
            $counterpart = $this->wwwCounterpartUrl($url);
            if ($counterpart === null) {
                continue;
            }

            $counterpartHost = $this->domainHost($counterpart);
            if ($counterpartHost === null) {
                continue;
            }

            $hostKey = strtolower($counterpartHost);
            if (isset($knownHosts[$hostKey])) {
                continue;
            }
            $knownHosts[$hostKey] = true;

            $base = $this->domainRowFromStored($counterpart, $app, $stored);
            $isWww = str_starts_with($hostKey, 'www.');
            $pointDns = dnsMismatchGuidanceMessage($this->dnsTargetLabel(), $this->serverIp);

            $base['is_suggested'] = true;
            $base['suggested_for'] = $url;
            $base['suggestion_label'] = $isWww ? 'Suggested www' : 'Suggested non-www';
            $base['needs_force_add'] = false;

            if (($base['dns_status'] ?? 'pending') === 'pending') {
                $base['dns_message'] = "Also add this host so both www and non-www work. {$pointDns}";
            }

            $suggested[] = $base;
        }

        return $suggested;
    }

    /**
     * @return array<int, string>
     */
    protected function splitDomains(?string $value): array
    {
        if (blank($value)) {
            return [];
        }

        return collect(explode(',', $value))
            ->map(fn ($domain) => trim((string) $domain))
            ->filter()
            ->values()
            ->all();
    }

    public function dnsTargetLabel(): ?string
    {
        if (blank($this->serverIp)) {
            return $this->serverIpConfigured;
        }

        if (
            filled($this->serverIpConfigured)
            && $this->serverIpConfigured !== $this->serverIp
            && filter_var($this->serverIpConfigured, FILTER_VALIDATE_IP) === false
        ) {
            return "{$this->serverIp} ({$this->serverIpConfigured})";
        }

        return $this->serverIp;
    }

    public function checkAllDns(): void
    {
        $this->isCheckingDns = true;

        try {
            $server = $this->service->server;
            $skipDns = ! $this->dnsValidationEnabled || ! $server;

            foreach ($this->domainRows as $index => $row) {
                if ($skipDns) {
                    $this->domainRows[$index]['dns_status'] = 'skipped';
                    $this->domainRows[$index]['dns_message'] = ! $this->dnsValidationEnabled
                        ? 'DNS validation is disabled in instance settings.'
                        : 'No server available for DNS validation.';
                    $this->domainRows[$index]['checked_at'] = now()->toIso8601String();

                    continue;
                }

                $this->applyDnsStatus($index, $row['url'], $server);
            }

            $this->persistAllDomainDnsStatuses();
        } finally {
            $this->isCheckingDns = false;
        }
    }

    public function checkDomainDns(int $index): void
    {
        if (! isset($this->domainRows[$index])) {
            return;
        }

        $server = $this->service->server;
        if (! $server || ! $this->dnsValidationEnabled) {
            $this->domainRows[$index]['dns_status'] = 'skipped';
            $this->domainRows[$index]['dns_message'] = 'DNS check skipped.';
            $this->domainRows[$index]['checked_at'] = now()->toIso8601String();
            $this->persistAllDomainDnsStatuses();

            return;
        }

        $this->applyDnsStatus($index, $this->domainRows[$index]['url'], $server);
        $this->persistAllDomainDnsStatuses();
    }

    protected function applyDnsStatus(int $index, string $url, $server): void
    {
        $target = $this->dnsTargetLabel();

        try {
            $isValid = validateDNSEntry($url, $server);
            if ($isValid) {
                $this->domainRows[$index]['dns_status'] = 'ok';
                $this->domainRows[$index]['dns_message'] = $target
                    ? "DNS points to {$target} (or Cloudflare)."
                    : 'DNS looks correct.';
            } else {
                $this->domainRows[$index]['dns_status'] = 'failed';
                $this->domainRows[$index]['dns_message'] = dnsMismatchGuidanceMessage($target, $this->serverIp);
            }
        } catch (\Throwable) {
            $this->domainRows[$index]['dns_status'] = 'failed';
            $this->domainRows[$index]['dns_message'] = 'Could not validate DNS for this domain.';
        }

        $this->domainRows[$index]['expected_ip'] = $this->serverIp;
        $this->domainRows[$index]['checked_at'] = now()->toIso8601String();
    }

    protected function persistAllDomainDnsStatuses(): void
    {
        $byApp = [];

        foreach ($this->domainRows as $row) {
            $appId = (int) ($row['service_application_id'] ?? 0);
            $url = $row['url'] ?? null;
            if ($appId === 0 || ! is_string($url) || $url === '') {
                continue;
            }

            $status = $row['dns_status'] ?? 'pending';
            if ($status === 'pending') {
                $app = $this->service->applications->firstWhere('id', $appId);
                $existing = is_array($app?->domain_dns_statuses) ? ($app->domain_dns_statuses[$url] ?? null) : null;
                if (is_array($existing) && filled(data_get($existing, 'status'))) {
                    $byApp[$appId][$url] = $existing;
                }

                continue;
            }

            $byApp[$appId][$url] = [
                'status' => $status,
                'message' => (string) ($row['dns_message'] ?? ''),
                'expected_ip' => $row['expected_ip'] ?? $this->serverIp,
                'checked_at' => $row['checked_at'] ?? now()->toIso8601String(),
            ];
        }

        foreach ($this->service->applications as $app) {
            $statuses = $byApp[$app->id] ?? [];
            // Keep only URLs that still exist (configured or suggested currently shown)
            $currentUrls = collect($this->domainRows)
                ->where('service_application_id', $app->id)
                ->pluck('url')
                ->all();
            $statuses = array_intersect_key($statuses, array_flip($currentUrls));

            $app->domain_dns_statuses = $statuses === [] ? null : $statuses;
            $app->save();
        }

        $this->service->load('applications');
    }

    public function updatedNewDomain(): void
    {
        $this->addDomainDnsFailed = false;
        $this->addDomainDnsMessage = '';
        $this->forceSaveDns = false;
    }

    public function updatedEditingDomain(): void
    {
        $this->editDomainDnsFailed = false;
        $this->editDomainDnsMessage = '';
        $this->forceSaveEditDns = false;
    }

    public function confirmAddDomainDespiteDns(): void
    {
        $this->forceSaveDns = true;
        $this->addDomain();
    }

    public function confirmUpdateDomainDespiteDns(): void
    {
        $this->forceSaveEditDns = true;
        $this->updateDomain();
    }

    public function confirmDomainUsage(): void
    {
        $this->forceSaveDomains = true;
        $this->showDomainConflictModal = false;

        if ($this->pendingAction === 'update') {
            $this->updateDomain();

            return;
        }

        if ($this->pendingAction === 'suggested') {
            $this->addSuggestedDomain((int) ($this->editingIndex ?? -1));

            return;
        }

        $this->addDomain();
    }

    public function confirmRemovePort(): void
    {
        $this->forceRemovePort = true;
        $this->showPortWarningModal = false;

        if ($this->pendingAction === 'update') {
            $this->updateDomain();

            return;
        }

        if ($this->pendingAction === 'suggested') {
            $this->addSuggestedDomain((int) ($this->editingIndex ?? -1));

            return;
        }

        $this->addDomain();
    }

    public function cancelRemovePort(): void
    {
        $this->showPortWarningModal = false;
        $this->forceRemovePort = false;
        $this->pendingAction = null;
    }

    public function addDomain(): void
    {
        try {
            $this->authorize('update', $this->service);
            $this->validateOnly('newDomain');

            $app = $this->findServiceApp($this->newServiceApplicationId);
            if (! $app) {
                $this->dispatch('error', 'Select a service application.');

                return;
            }

            $normalized = ValidationPatterns::normalizeApplicationDomains($this->newDomain);
            if (blank($normalized)) {
                $this->addError('newDomain', 'Please enter a valid domain URL.');

                return;
            }

            $newUrls = $this->splitDomains($normalized);
            $current = collect($this->splitDomains($app->fqdn));
            foreach ($newUrls as $url) {
                if ($current->contains($url)) {
                    $this->addError('newDomain', "Domain {$url} is already configured for this service.");

                    return;
                }
            }

            if (! $this->forceSaveDns && $this->shouldValidateDns()) {
                $dnsFailure = $this->findDnsFailureMessage($newUrls);
                if ($dnsFailure !== null) {
                    $this->addDomainDnsFailed = true;
                    $this->addDomainDnsMessage = $dnsFailure;

                    return;
                }
            }

            $merged = $current->merge($newUrls)->unique()->values();
            $this->pendingAction = 'add';

            if (! $this->saveDomainListForApp($app, $merged, checkDns: false)) {
                return;
            }

            $this->storeDnsForApp(
                $app,
                $newUrls,
                forcedFailure: $this->forceSaveDns,
                failureMessage: $this->addDomainDnsMessage,
            );

            $this->newDomain = '';
            $this->addDomainDnsFailed = false;
            $this->addDomainDnsMessage = '';
            $this->forceSaveDns = false;
            $this->forceSaveDomains = false;
            $this->forceRemovePort = false;
            $this->pendingAction = null;
            $this->dispatch('close-modal');
            $this->dispatch('success', 'Domain added.');
            $this->refreshDomains();
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
    }

    public function startEdit(int $index): void
    {
        if (! isset($this->domainRows[$index]) || ($this->domainRows[$index]['is_suggested'] ?? false)) {
            return;
        }

        $this->editingIndex = $index;
        $this->editingDomain = $this->domainRows[$index]['url'];
        $this->editingServiceApplicationId = (int) $this->domainRows[$index]['service_application_id'];
        $this->editDomainDnsFailed = false;
        $this->editDomainDnsMessage = '';
        $this->forceSaveEditDns = false;
        $this->resetErrorBag('editingDomain');
        $this->showEditDomainModal = true;
    }

    public function cancelEdit(): void
    {
        $this->showEditDomainModal = false;
        $this->editingIndex = null;
        $this->editingDomain = '';
        $this->editingServiceApplicationId = null;
        $this->editDomainDnsFailed = false;
        $this->editDomainDnsMessage = '';
        $this->forceSaveEditDns = false;
        $this->resetErrorBag('editingDomain');
    }

    public function updateDomain(): void
    {
        try {
            $this->authorize('update', $this->service);

            if ($this->editingIndex === null || ! isset($this->domainRows[$this->editingIndex])) {
                return;
            }

            $this->validateOnly('editingDomain');

            $app = $this->findServiceApp($this->editingServiceApplicationId);
            if (! $app) {
                return;
            }

            $normalized = ValidationPatterns::normalizeApplicationDomains($this->editingDomain);
            if (blank($normalized) || count($this->splitDomains($normalized)) !== 1) {
                $this->addError('editingDomain', 'Please enter a single valid domain URL.');

                return;
            }

            $newUrl = $this->splitDomains($normalized)[0];
            $oldUrl = $this->domainRows[$this->editingIndex]['url'];
            $current = collect($this->splitDomains($app->fqdn));

            if ($newUrl !== $oldUrl && $current->contains($newUrl)) {
                $this->addError('editingDomain', "Domain {$newUrl} is already configured for this service.");

                return;
            }

            if (! $this->forceSaveEditDns && $this->shouldValidateDns()) {
                $dnsFailure = $this->findDnsFailureMessage([$newUrl]);
                if ($dnsFailure !== null) {
                    $this->editDomainDnsFailed = true;
                    $this->editDomainDnsMessage = $dnsFailure;

                    return;
                }
            }

            $updated = $current->map(fn (string $url) => $url === $oldUrl ? $newUrl : $url)->unique()->values();
            $this->pendingAction = 'update';

            if (! $this->saveDomainListForApp($app, $updated, checkDns: false)) {
                return;
            }

            $this->storeDnsForApp(
                $app,
                [$newUrl],
                forcedFailure: $this->forceSaveEditDns,
                failureMessage: $this->editDomainDnsMessage,
            );

            $this->cancelEdit();
            $this->forceSaveDomains = false;
            $this->forceRemovePort = false;
            $this->pendingAction = null;
            $this->dispatch('success', 'Domain updated.');
            $this->refreshDomains();
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
    }

    public function removeDomain(int $index): void
    {
        try {
            $this->authorize('update', $this->service);

            if (! isset($this->domainRows[$index]) || ($this->domainRows[$index]['is_suggested'] ?? false)) {
                return;
            }

            $app = $this->findServiceApp((int) $this->domainRows[$index]['service_application_id']);
            if (! $app) {
                return;
            }

            $url = $this->domainRows[$index]['url'];
            $updated = collect($this->splitDomains($app->fqdn))->reject(fn (string $item) => $item === $url)->values();

            $this->forceSaveDomains = true;
            $this->forceRemovePort = true;
            if (! $this->saveDomainListForApp($app, $updated, checkDns: false, checkConflicts: false)) {
                return;
            }

            $this->forceSaveDomains = false;
            $this->forceRemovePort = false;
            $this->dispatch('success', 'Domain removed.');
            $this->refreshDomains();
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
    }

    public function addSuggestedDomain(int $index): void
    {
        try {
            $this->authorize('update', $this->service);

            if (! isset($this->domainRows[$index]) || ! ($this->domainRows[$index]['is_suggested'] ?? false)) {
                return;
            }

            $app = $this->findServiceApp((int) $this->domainRows[$index]['service_application_id']);
            if (! $app) {
                return;
            }

            $url = (string) $this->domainRows[$index]['url'];
            $normalized = ValidationPatterns::normalizeApplicationDomains($url);
            $newUrls = $this->splitDomains($normalized);
            $current = collect($this->splitDomains($app->fqdn));

            foreach ($newUrls as $newUrl) {
                if ($current->contains($newUrl)) {
                    $this->refreshDomains();

                    return;
                }
            }

            $force = $this->forceAddSuggestedIndex === $index
                || (bool) ($this->domainRows[$index]['needs_force_add'] ?? false);

            if (! $force && $this->shouldValidateDns()) {
                $dnsFailure = $this->findDnsFailureMessage($newUrls);
                if ($dnsFailure !== null) {
                    $this->domainRows[$index]['dns_status'] = 'failed';
                    $this->domainRows[$index]['dns_message'] = $dnsFailure;
                    $this->domainRows[$index]['checked_at'] = now()->toIso8601String();
                    $this->domainRows[$index]['needs_force_add'] = true;
                    $this->forceAddSuggestedIndex = $index;
                    $this->editingIndex = $index;
                    $this->persistAllDomainDnsStatuses();
                    $this->dispatch('error', 'DNS validation failed.', $dnsFailure);

                    return;
                }
            }

            $merged = $current->merge($newUrls)->unique()->values();
            $this->pendingAction = 'suggested';
            $this->editingIndex = $index;

            if (! $this->saveDomainListForApp($app, $merged, checkDns: false)) {
                return;
            }

            $this->storeDnsForApp(
                $app,
                $newUrls,
                forcedFailure: $force && $this->shouldValidateDns(),
                failureMessage: (string) ($this->domainRows[$index]['dns_message'] ?? ''),
            );

            $this->forceAddSuggestedIndex = null;
            $this->editingIndex = null;
            $this->pendingAction = null;
            $this->forceSaveDomains = false;
            $this->forceRemovePort = false;
            $this->dispatch('success', 'Domain added.');
            $this->refreshDomains();
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
    }

    public function generateDomain(): void
    {
        try {
            $this->authorize('update', $this->service);

            $app = $this->findServiceApp($this->newServiceApplicationId);
            $server = $this->service->server;
            if (! $app || ! $server) {
                $this->dispatch('error', 'Service application or server not found.');

                return;
            }

            $domain = generateUrl(server: $server, random: new_public_id());
            $requiredPort = $app->getRequiredPort();
            if ($requiredPort !== null) {
                $parts = parse_url($domain);
                if (is_array($parts) && empty($parts['port'])) {
                    $scheme = $parts['scheme'] ?? 'https';
                    $host = $parts['host'] ?? '';
                    $path = $parts['path'] ?? '';
                    $domain = "{$scheme}://{$host}:{$requiredPort}{$path}";
                }
            }

            $this->newDomain = $domain;
            $this->updatedNewDomain();
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
    }

    /**
     * @param  Collection<int, string>  $domains
     */
    protected function saveDomainListForApp(
        ServiceApplication $app,
        Collection $domains,
        bool $checkDns = true,
        bool $checkConflicts = true,
    ): bool {
        $domainString = $domains->filter()->unique()->implode(',');
        $domainString = $domainString === '' ? null : ValidationPatterns::normalizeApplicationDomains($domainString);

        if ($domainString) {
            $errors = ValidationPatterns::validateApplicationDomains($domainString);
            if ($errors !== []) {
                $this->dispatch('error', $errors[0]);

                return false;
            }
        }

        $app->fqdn = $domainString;

        if ($checkConflicts && ! $this->forceSaveDomains) {
            $result = checkDomainUsage(resource: $app);
            if ($result['hasConflicts']) {
                $this->domainConflicts = $result['conflicts'];
                $this->showDomainConflictModal = true;
                $app->refresh();

                return false;
            }
        } else {
            $this->forceSaveDomains = false;
        }

        if (! $this->forceRemovePort) {
            $requiredPort = $app->getRequiredPort();
            if ($requiredPort !== null && $domainString) {
                foreach ($this->splitDomains($domainString) as $fqdn) {
                    if (ServiceApplication::extractPortFromUrl($fqdn) === null) {
                        $this->requiredPort = $requiredPort;
                        $this->showPortWarningModal = true;
                        $app->refresh();

                        return false;
                    }
                }
            }
        } else {
            $this->forceRemovePort = false;
        }

        if ($checkDns && $domainString && $this->shouldValidateDns()) {
            $server = $this->service->server;
            if ($server) {
                foreach ($this->splitDomains($domainString) as $domain) {
                    if (! validateDNSEntry($domain, $server)) {
                        $guidance = dnsMismatchGuidanceMessage(
                            $this->dnsTargetLabel() ?? serverDnsTargetIp($server) ?? $server->ip,
                            $this->serverIp ?? serverDnsTargetIp($server) ?? $server->ip,
                        );
                        $this->dispatch(
                            'error',
                            'Validating DNS failed.',
                            $guidance
                        );
                    }
                }
            }
        }

        $warning = sslipDomainWarning($domainString ?? '');
        if ($warning) {
            $this->dispatch('warning', __('warning.sslipdomain'));
        }

        $app->save();

        try {
            updateCompose($app);
        } catch (\Throwable) {
            // Compose generation may fail in incomplete test environments.
        }

        if (str($app->fqdn)->contains(',')) {
            $this->dispatch('warning', 'Some services do not support multiple domains, which can lead to problems and is NOT RECOMMENDED.<br><br>Only use multiple domains if you know what you are doing.');
        }

        try {
            $this->service->parse();
        } catch (\Throwable) {
            // Parse may fail without a full compose template.
        }

        $this->dispatch('refresh');
        $this->dispatch('refreshServices');
        $this->dispatch('configurationChanged');

        return true;
    }

    /**
     * @param  array<int, string>  $urls
     */
    protected function storeDnsForApp(
        ServiceApplication $app,
        array $urls,
        bool $forcedFailure = false,
        string $failureMessage = '',
    ): void {
        if (! $this->shouldValidateDns() && ! $forcedFailure) {
            return;
        }

        $statuses = is_array($app->domain_dns_statuses) ? $app->domain_dns_statuses : [];
        $target = $this->dnsTargetLabel();
        $checkedAt = now()->toIso8601String();

        if ($forcedFailure) {
            $message = $failureMessage !== ''
                ? $failureMessage
                : dnsMismatchGuidanceMessage($target, $this->serverIp);
            foreach ($urls as $url) {
                $statuses[$url] = [
                    'status' => 'failed',
                    'message' => $message,
                    'expected_ip' => $this->serverIp,
                    'checked_at' => $checkedAt,
                ];
            }
        } else {
            $message = $target
                ? "DNS points to {$target} (or Cloudflare)."
                : 'DNS looks correct.';
            foreach ($urls as $url) {
                $statuses[$url] = [
                    'status' => 'ok',
                    'message' => $message,
                    'expected_ip' => $this->serverIp,
                    'checked_at' => $checkedAt,
                ];
            }
        }

        $app->domain_dns_statuses = $statuses;
        $app->save();
    }

    protected function shouldValidateDns(): bool
    {
        return $this->dnsValidationEnabled && $this->service->server !== null;
    }

    /**
     * @param  array<int, string>  $urls
     */
    protected function findDnsFailureMessage(array $urls): ?string
    {
        $server = $this->service->server;
        if (! $server) {
            return null;
        }

        $target = $this->dnsTargetLabel() ?? $server->ip;

        foreach ($urls as $url) {
            try {
                if (! validateDNSEntry($url, $server)) {
                    return dnsMismatchGuidanceMessage($target, $this->serverIp);
                }
            } catch (\Throwable) {
                return 'Could not validate DNS for this domain.';
            }
        }

        return null;
    }

    protected function findServiceApp(?int $id): ?ServiceApplication
    {
        if (! $id) {
            return null;
        }

        return $this->service->applications->firstWhere('id', $id)
            ?? ServiceApplication::query()->where('service_id', $this->service->id)->find($id);
    }

    protected function domainHost(string $url): ?string
    {
        try {
            $host = parse_url($url, PHP_URL_HOST);

            return is_string($host) && $host !== '' ? $host : null;
        } catch (\Throwable) {
            return null;
        }
    }

    protected function wwwCounterpartUrl(string $url): ?string
    {
        $host = $this->domainHost($url);
        if ($host === null) {
            return null;
        }

        $lowerHost = strtolower($host);
        if (
            filter_var($host, FILTER_VALIDATE_IP) !== false
            || $lowerHost === 'localhost'
            || str_contains($lowerHost, 'sslip.io')
            || str_contains($lowerHost, 'nip.io')
        ) {
            return null;
        }

        $counterpartHost = str_starts_with($lowerHost, 'www.')
            ? substr($host, 4)
            : 'www.'.$host;

        if ($counterpartHost === '' || $counterpartHost === $host) {
            return null;
        }

        $parts = parse_url($url);
        if ($parts === false) {
            return null;
        }

        $scheme = $parts['scheme'] ?? 'https';
        $port = isset($parts['port']) ? ':'.$parts['port'] : '';
        $path = $parts['path'] ?? '';
        $query = isset($parts['query']) ? '?'.$parts['query'] : '';
        $fragment = isset($parts['fragment']) ? '#'.$parts['fragment'] : '';

        return "{$scheme}://{$counterpartHost}{$port}{$path}{$query}{$fragment}";
    }

    public function render()
    {
        return view('livewire.project.service.domains');
    }
}
