<?php

namespace App\Livewire\Project\Service;

use App\Actions\Shared\CheckDomainDns;
use App\Jobs\CheckDomainDnsJob;
use App\Livewire\Concerns\InteractsWithCloudflareDomainConnect;
use App\Livewire\Project\Shared\ConfigurationChecker;
use App\Models\Server;
use App\Models\Service;
use App\Models\ServiceApplication;
use App\Support\DomainPortOverrides;
use App\Support\DomainUrlParts;
use App\Support\ValidationPatterns;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Livewire\Component;

class Domains extends Component
{
    use AuthorizesRequests;
    use InteractsWithCloudflareDomainConnect;

    protected bool $notifyRedirectUpdate = true;

    public Service $service;

    /** @var array<int, array{id: int, name: string, image: ?string, required_port: ?int}> */
    public array $serviceApps = [];

    /**
     * Per service-application www/non-www redirect direction.
     *
     * @var array<int|string, string>
     */
    public array $serviceRedirects = [];

    /** @var array<int|string, bool> */
    public array $forceHttpsRedirects = [];

    /** Service application id when a pending domain conflict belongs to setServiceRedirect. */
    public ?int $pendingRedirectServiceApplicationId = null;

    /** @var array<int, array<string, mixed>> */
    public array $domainRows = [];

    public ?int $newServiceApplicationId = null;

    public string $newDomain = '';

    public array $newDomainParts = ['scheme' => 'https', 'host' => '', 'port' => '', 'path' => ''];

    public bool $newDomainPartsChanged = false;

    public ?int $editingIndex = null;

    public string $editingDomain = '';

    public array $editingDomainParts = ['scheme' => 'https', 'host' => '', 'port' => '', 'path' => ''];

    public bool $editingDomainPartsChanged = false;

    public ?int $editingServiceApplicationId = null;

    public string $editingIndexing = 'index';

    public string $editingRedirect = 'both';

    public string $editingOriginalRedirect = 'both';

    public bool $editingDomainWasRegenerated = false;

    public ?string $editingGeneratedHost = null;

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
            'editingIndexing' => 'string|required|in:index,noindex',
            'editingRedirect' => 'string|required|in:both,www,non-www',
            'newServiceApplicationId' => 'nullable|integer',
            'serviceRedirects' => 'array',
            'serviceRedirects.*' => 'string|in:both,www,non-www',
            'forceHttpsRedirects' => 'array',
            'forceHttpsRedirects.*' => 'boolean',
        ];
    }

    public function mount(): void
    {
        $this->authorize('view', $this->service);
        $this->loadDomainState();
    }

    public function refreshDomains(): void
    {
        $editingRow = $this->editingIndex !== null ? ($this->domainRows[$this->editingIndex] ?? null) : null;

        $this->service->refresh();
        $this->service->load(['applications', 'server']);
        $this->loadDomainState();

        if ($editingRow !== null) {
            $index = collect($this->domainRows)->search(fn (array $row): bool => $row['url'] === $editingRow['url']
                && (int) $row['service_application_id'] === (int) $editingRow['service_application_id']);
            $this->editingIndex = $index === false ? null : (int) $index;
        }
    }

    public function pollDnsChecks(): void
    {
        $this->authorize('view', $this->service);

        $checkingRows = collect($this->domainRows)
            ->where('dns_status', 'checking')
            ->values();

        $this->refreshDomains();

        foreach ($checkingRows as $checkingRow) {
            $row = collect($this->domainRows)->first(fn (array $row): bool => $row['url'] === $checkingRow['url']
                && (int) $row['service_application_id'] === (int) $checkingRow['service_application_id']);

            if (! is_array($row) || $row['dns_status'] === 'checking') {
                continue;
            }

            $this->dispatchDnsCheckNotification($row['url'], $row['dns_status']);
        }
    }

    protected function dispatchDnsCheckNotification(string $url, string $status): void
    {
        $host = parse_url($url, PHP_URL_HOST) ?: $url;

        match ($status) {
            'ok' => $this->dispatch('success', "DNS is configured correctly for {$host}."),
            'failed' => $this->dispatch('error', "DNS is not configured for {$host}. Review the required DNS record."),
            default => $this->dispatch('info', "DNS check skipped for {$host}."),
        };
    }

    public function toggleNoindexDomain(int $serviceApplicationId, string $domain, string|bool $indexing): void
    {
        $application = $this->service->applications()->findOrFail($serviceApplicationId);
        $this->authorize('update', $application);

        $noindex = $indexing === true || $indexing === 'noindex';
        $domains = $application->noindexDomains();
        $domains = $noindex ? $domains->push($domain) : $domains->reject(fn (string $item) => $item === $domain);

        $application->setNoindexDomains($domains);
        $application->save();
        $this->service->parse();
        $this->refreshDomains();
        $this->dispatch('configurationChanged')->to(ConfigurationChecker::class);
        $this->dispatch('success', 'Search engine indexing updated.');
    }

    public function updateForceHttps(int $serviceApplicationId, bool $enabled): void
    {
        $application = $this->service->applications()->findOrFail($serviceApplicationId);
        $this->authorize('update', $application);

        $this->forceHttpsRedirects[$serviceApplicationId] = $enabled;
        $this->validateOnly("forceHttpsRedirects.{$serviceApplicationId}");

        $application->is_force_https_enabled = $enabled;
        $application->save();
        $this->service->parse();
        $this->refreshDomains();
        $this->dispatch('configurationChanged')->to(ConfigurationChecker::class);
        $this->dispatch('success', 'HTTP to HTTPS redirect updated.');
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

        $this->forceHttpsRedirects = $this->service->applications
            ->mapWithKeys(fn (ServiceApplication $app) => [$app->id => $app->isForceHttpsEnabled()])
            ->all();

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

        $pendingRedirect = $this->serviceRedirects[$this->pendingRedirectServiceApplicationId] ?? null;
        $this->serviceRedirects = [];
        foreach ($this->service->applications as $app) {
            $this->serviceRedirects[$app->id] = $this->normalizeRedirect(
                $this->pendingAction === 'redirect' && $app->id === $this->pendingRedirectServiceApplicationId
                    ? $pendingRedirect
                    : $app->redirect
            );
        }

        if ($this->newServiceApplicationId === null && count($this->serviceApps) > 0) {
            $this->newServiceApplicationId = $this->serviceApps[0]['id'];
        }

        // Do not auto-promote www/non-www pairs here: load/refresh also runs after
        // removeDomain, and re-adding counterparts would undo intentional deletes.
        // Pairs are still ensured on setServiceRedirect, addDomain, etc.

        $this->domainRows = $this->buildDomainRows();
    }

    protected function normalizeRedirect(?string $redirect): string
    {
        return in_array($redirect, ['www', 'non-www', 'both'], true) ? $redirect : 'both';
    }

    protected function serviceRedirectFor(?int $serviceApplicationId): string
    {
        if (! $serviceApplicationId) {
            return 'both';
        }

        return $this->normalizeRedirect($this->serviceRedirects[$serviceApplicationId] ?? null);
    }

    public function updatedServiceRedirects(string $redirect, int|string $serviceApplicationId): void
    {
        $this->updateServiceRedirect((int) $serviceApplicationId, $redirect);
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

        }

        return collect($rows)
            ->sortBy(fn (array $row): int => ($row['dns_status'] ?? null) === 'failed' ? 0 : 1)
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $stored
     * @return array<string, mixed>
     */
    protected function domainRowFromStored(string $url, ServiceApplication $app, array $stored): array
    {
        $entry = $stored[$url] ?? null;
        $displayName = $app->human_name ?: $app->name;
        $port = $this->effectiveDomainInternalPort($url, $app);

        $row = [
            'service_application_id' => $app->id,
            'service_name' => $displayName,
            'service_image' => $app->image,
            'url' => $url,
            'internal_port' => $port['internal_port'],
            'has_port_override' => $port['has_port_override'],
            'dns_status' => 'pending',
            'dns_message' => 'Not checked yet.',
            'expected_ip' => $this->serverIp,
            'checked_at' => null,
            'check_id' => null,
            'is_suggested' => false,
            'suggested_for' => null,
            'suggestion_label' => null,
            'needs_force_add' => false,
        ];

        if (is_array($entry) && filled(data_get($entry, 'status'))) {
            $row['dns_status'] = (string) data_get($entry, 'status', 'pending');
            $row['dns_message'] = (string) data_get($entry, 'message', 'Not checked yet.');
            $row['expected_ip'] = data_get($entry, 'expected_ip') ?: $this->serverIp;
            $row['checked_at'] = data_get($entry, 'checked_at');
            $row['check_id'] = data_get($entry, 'check_id');
        }

        return $row;
    }

    /**
     * @return array{internal_port: ?int, has_port_override: bool}
     */
    protected function effectiveDomainInternalPort(string $url, ServiceApplication $app): array
    {
        $canonical = DomainPortOverrides::withoutPort($url);
        $overrides = $app->domain_port_overrides ?? [];
        $legacyPortPart = DomainUrlParts::split($url)['port'] ?? '';
        $legacyPort = $legacyPortPart !== '' ? (int) $legacyPortPart : null;

        if (array_key_exists($canonical, $overrides)) {
            return [
                'internal_port' => (int) $overrides[$canonical],
                'has_port_override' => true,
            ];
        }

        if ($legacyPort !== null && $legacyPort > 0) {
            return [
                'internal_port' => $legacyPort,
                'has_port_override' => true,
            ];
        }

        $requiredPort = $app->getRequiredPort();

        return [
            'internal_port' => ($requiredPort !== null && $requiredPort > 0) ? $requiredPort : null,
            'has_port_override' => false,
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
            $meta = $this->suggestedDomainMeta($isWww, $this->serviceRedirectFor($app->id));

            $base['is_suggested'] = true;
            $base['suggested_for'] = $url;
            $base['suggestion_label'] = null;
            $base['suggestion_role'] = $meta['role'];
            $base['needs_force_add'] = false;
            $base['dns_message'] = $meta['pending_message'];

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

    protected function authorizeUpdateForDomainConnect(): void
    {
        $this->authorize('update', $this->service);
    }

    protected function usesInstanceNetworkAddressesForDnsHints(): bool
    {
        return $this->service->server?->id === 0;
    }

    public function checkAllDns(): void
    {
        $this->authorize('update', $this->service);

        foreach ($this->domainRows as $row) {
            $application = $this->findServiceApp((int) $row['service_application_id']);
            if ($application) {
                $this->queueUrlsDns([$row['url']], $application);
            }
        }
    }

    public function checkDomainDns(int $index): void
    {
        $this->authorize('update', $this->service);

        if (! isset($this->domainRows[$index])) {
            return;
        }

        $row = $this->domainRows[$index];
        $application = $this->findServiceApp((int) $row['service_application_id']);
        if ($application) {
            $this->queueUrlsDns([$row['url']], $application);
        }
    }

    protected function applyDnsStatus(int $index, Server $server): void
    {
        $this->applyDnsStatuses([$index], $server);
    }

    /**
     * @param  array<int, int>  $indexes
     */
    protected function applyDnsStatuses(array $indexes, Server $server): void
    {
        $entries = [];

        foreach ($indexes as $index) {
            $entries[(string) $index] = $this->domainRows[$index]['url'];
        }

        $results = CheckDomainDns::run($entries, $server, $this->serverIp);

        foreach ($results as $index => $result) {
            $index = (int) $index;
            $this->domainRows[$index]['dns_status'] = $result['status'];
            $this->domainRows[$index]['dns_message'] = $result['message'];
            $this->domainRows[$index]['expected_ip'] = $result['expected_ip'];
            $this->domainRows[$index]['checked_at'] = $result['checked_at'];
            $this->domainRows[$index]['check_id'] = null;
            $this->decorateSuggestedDomainAfterDnsCheck($index);
        }
    }

    /**
     * Keep suggested-row copy short after a DNS check (no role badge).
     */
    protected function decorateSuggestedDomainAfterDnsCheck(int $index): void
    {
        if (! ($this->domainRows[$index]['is_suggested'] ?? false)) {
            return;
        }

        $isWww = str_starts_with(strtolower((string) $this->domainHost((string) $this->domainRows[$index]['url'])), 'www.');
        $appId = (int) ($this->domainRows[$index]['service_application_id'] ?? 0);
        $meta = $this->suggestedDomainMeta($isWww, $this->serviceRedirectFor($appId > 0 ? $appId : null));
        $this->domainRows[$index]['dns_message'] = $meta['pending_message'];
        $this->domainRows[$index]['suggestion_label'] = null;
        $this->domainRows[$index]['suggestion_role'] = $meta['role'];
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
                'check_id' => $row['check_id'] ?? null,
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

            DB::transaction(function () use ($app, &$statuses): void {
                $application = ServiceApplication::query()->lockForUpdate()->findOrFail($app->id);
                $storedStatuses = $application->domain_dns_statuses ?? [];

                foreach ($statuses as $key => $status) {
                    $localCheckId = $status['check_id'] ?? null;
                    $storedCheckId = $storedStatuses[$key]['check_id'] ?? null;

                    if ($storedCheckId !== null && $localCheckId !== $storedCheckId) {
                        $statuses[$key] = $storedStatuses[$key];

                        continue;
                    }

                    if ($status['status'] === 'checking' && isset($storedStatuses[$key]) && $storedStatuses[$key]['status'] !== 'checking') {
                        $statuses[$key] = $storedStatuses[$key];
                    }
                }

                $application->domain_dns_statuses = $statuses === [] ? null : $statuses;
                $application->save();
            });

            $app->domain_dns_statuses = $statuses === [] ? null : $statuses;
        }

        $this->service->load('applications');
    }

    protected function pruneDomainDnsStatusesToCurrentDomains(): void
    {
        $this->refreshDomains();
        $this->persistAllDomainDnsStatuses();
    }

    public function updatedNewDomain(): void
    {
        $this->resetAddDomainDnsGate();
    }

    public function updatedNewDomainParts(): void
    {
        $this->newDomainPartsChanged = true;
        $this->resetAddDomainDnsGate();
    }

    public function resetAddDomainDnsGate(): void
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

    public function updatedEditingDomainParts(): void
    {
        $this->editingDomainPartsChanged = true;
        $this->updatedEditingDomain();
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

        if ($this->pendingAction === 'redirect' && $this->pendingRedirectServiceApplicationId) {
            $this->setServiceRedirect((int) $this->pendingRedirectServiceApplicationId);

            return;
        }

        $this->addDomain();
    }

    /**
     * Labels/copy for a suggested www or non-www host based on Direction.
     *
     * @return array{label: string, role: string, pending_message: string, dns_suffix: string}
     */
    protected function suggestedDomainMeta(bool $suggestedIsWww, ?string $redirectOverride = null): array
    {
        $pendingMessage = 'Not configured yet.';
        $redirect = $this->normalizeRedirect($redirectOverride);

        return match ($redirect) {
            'www' => $suggestedIsWww
                ? [
                    'label' => 'Not added · canonical www',
                    'role' => 'canonical',
                    'pending_message' => $pendingMessage,
                    'dns_suffix' => '',
                ]
                : [
                    'label' => 'Not added · redirect source',
                    'role' => 'redirect_source',
                    'pending_message' => $pendingMessage,
                    'dns_suffix' => '',
                ],
            'non-www' => $suggestedIsWww
                ? [
                    'label' => 'Not added · redirect source',
                    'role' => 'redirect_source',
                    'pending_message' => $pendingMessage,
                    'dns_suffix' => '',
                ]
                : [
                    'label' => 'Not added · canonical non-www',
                    'role' => 'canonical',
                    'pending_message' => $pendingMessage,
                    'dns_suffix' => '',
                ],
            default => [
                'label' => $suggestedIsWww ? 'Not added · www' : 'Not added · non-www',
                'role' => 'pair',
                'pending_message' => $pendingMessage,
                'dns_suffix' => '',
            ],
        };
    }

    public function updateServiceRedirect(int $serviceApplicationId, string $redirect): void
    {
        $this->serviceRedirects[$serviceApplicationId] = $redirect;
        $this->setServiceRedirect($serviceApplicationId);
    }

    /**
     * @param  mixed  ...$modalArgs  Extra args from modal-confirmation (password, etc.)
     */
    public function setServiceRedirect(int $serviceApplicationId, mixed ...$modalArgs): void
    {
        try {
            $this->authorize('update', $this->service);

            $app = $this->findServiceApp($serviceApplicationId);
            if (! $app) {
                $this->dispatch('error', 'Service application not found.');

                return;
            }

            $this->validateOnly("serviceRedirects.{$serviceApplicationId}");
            $redirect = $this->normalizeRedirect($this->serviceRedirects[$serviceApplicationId] ?? null);
            $this->serviceRedirects[$serviceApplicationId] = $redirect;
            $this->pendingRedirectServiceApplicationId = $serviceApplicationId;

            $addedDomains = [];
            $saved = DB::transaction(function () use ($app, $redirect, &$addedDomains): bool {
                // Promote the optional www/non-www suggestion to a real domain for redirects.
                if (in_array($redirect, ['www', 'non-www'], true)) {
                    $domainsBeforePairing = collect($this->splitDomains($app->fqdn));
                    if (! $this->ensureWwwNonWwwPairsConfigured($app)) {
                        return false;
                    }
                    $app->refresh();
                    $addedDomains = collect($this->splitDomains($app->fqdn))->diff($domainsBeforePairing)->values()->all();
                }

                $domains = collect($this->splitDomains($app->fqdn));
                if (! $this->assertRedirectDomainsPresent($redirect, $domains)) {
                    return false;
                }

                $app->redirect = $redirect;
                $app->save();
                updateCompose($app);
                $this->service->parse();

                return true;
            });

            if (! $saved) {
                return;
            }

            $this->pendingAction = null;
            $this->pendingRedirectServiceApplicationId = null;
            $this->forceSaveDomains = false;
            $this->forceRemovePort = false;
            if ($this->notifyRedirectUpdate) {
                $this->dispatch('success', 'Redirect updated.');
            }
            $this->dispatch('configurationChanged');
            $this->pruneDomainDnsStatusesToCurrentDomains();
            $this->refreshDomains();
            $this->queueUrlsDns($addedDomains, $app);
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
    }

    /**
     * @param  Collection<int, string>  $domains
     */
    protected function assertRedirectDomainsPresent(string $redirect, Collection $domains): bool
    {
        if (! in_array($redirect, ['www', 'non-www'], true)) {
            return true;
        }

        $hasWww = $domains->filter(
            fn ($fqdn) => str_starts_with(strtolower((string) $this->domainHost((string) $fqdn)), 'www.')
        )->count();
        $hasNonWww = $domains->filter(
            function ($fqdn) {
                $host = strtolower((string) $this->domainHost((string) $fqdn));

                return $host !== '' && ! str_starts_with($host, 'www.');
            }
        )->count();

        $dnsHint = dnsMismatchGuidanceMessage(
            $this->dnsTargetLabel() ?? $this->serverIp,
            $this->serverIp,
        );

        // Redirects need both hosts: canonical target + source the proxy redirects from.
        if ($hasWww === 0 || $hasNonWww === 0) {
            $missing = $hasWww === 0 ? 'www' : 'non-www';
            $this->dispatch(
                'error',
                "Redirect requires both www and non-www domains, but the {$missing} host could not be added automatically (e.g. only IP/sslip hosts).<br><br>Please add the {$missing} domain manually ({$dnsHint})."
            );

            return false;
        }

        return true;
    }

    /**
     * When saved redirect is www/non-www, ensure missing counterparts exist as real domains.
     *
     * @return array<int, string> newly added domain URLs
     */
    protected function syncRedirectDomainPairs(?ServiceApplication $app = null): array
    {
        $user = auth()->user();
        if ($user === null || ! $user->can('update', $this->service)) {
            return [];
        }

        if ($app === null) {
            $added = [];
            foreach ($this->service->applications as $serviceApp) {
                $added = array_merge($added, $this->syncRedirectDomainPairs($serviceApp));
            }

            return array_values(array_unique($added));
        }

        $redirect = $this->normalizeRedirect($app->redirect ?? null);
        if (! in_array($redirect, ['www', 'non-www'], true)) {
            return [];
        }

        $before = collect($this->splitDomains($app->fqdn))->all();
        if (! $this->ensureWwwNonWwwPairsConfigured($app)) {
            return [];
        }

        $app->refresh();
        $after = collect($this->splitDomains($app->fqdn));

        return $after->reject(fn (string $url) => in_array($url, $before, true))->values()->all();
    }

    /**
     * Persist missing www/non-www counterparts as normal domains (not suggestions).
     *
     * @return bool false when save was blocked (e.g. domain conflict modal shown)
     */
    protected function ensureWwwNonWwwPairsConfigured(ServiceApplication $app): bool
    {
        $current = collect($this->splitDomains($app->fqdn));
        $knownHosts = [];

        foreach ($current as $url) {
            $host = $this->domainHost($url);
            if ($host !== null) {
                $knownHosts[strtolower($host)] = true;
            }
        }

        $toAdd = collect();
        $portOverrides = $app->domain_port_overrides ?? [];
        foreach ($current as $url) {
            $counterpart = $this->wwwCounterpartUrl($url, forRedirectPairing: true);
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

            $port = $this->effectiveDomainInternalPort($url, $app);
            if ($port['has_port_override']) {
                $portOverrides[DomainPortOverrides::withoutPort($counterpart)] = $port['internal_port'];
            }

            $knownHosts[$hostKey] = true;
            $toAdd->push($counterpart);
        }

        if ($toAdd->isEmpty()) {
            return true;
        }

        $app->domain_port_overrides = $portOverrides ?: null;
        $merged = $current->merge($toAdd)->unique()->values();
        $this->pendingAction = 'redirect';
        $this->pendingRedirectServiceApplicationId = $app->id;

        // Counterparts inherit an existing port, so only domain conflicts need confirmation.
        if (! $this->saveDomainListForApp($app, $merged, checkPorts: false)) {
            return false;
        }

        $this->pendingAction = null;
        $this->pendingRedirectServiceApplicationId = null;

        return true;
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

        if ($this->pendingAction === 'redirect' && $this->pendingRedirectServiceApplicationId) {
            $this->setServiceRedirect($this->pendingRedirectServiceApplicationId);

            return;
        }

        $this->addDomain();
    }

    public function cancelRemovePort(): void
    {
        $this->authorize('update', $this->service);

        if ($this->pendingAction === 'redirect' && $this->pendingRedirectServiceApplicationId) {
            $app = $this->findServiceApp($this->pendingRedirectServiceApplicationId);
            $this->serviceRedirects[$this->pendingRedirectServiceApplicationId] = $this->normalizeRedirect($app?->redirect);
        }
        $this->pendingRedirectServiceApplicationId = null;
        $this->showPortWarningModal = false;
        $this->forceSaveDomains = false;
        $this->forceRemovePort = false;
        $this->pendingAction = null;
    }

    public function addDomain(): void
    {
        try {
            $this->authorize('update', $this->service);
            if ($this->newDomainPartsChanged) {
                $this->newDomain = DomainUrlParts::compose(...$this->newDomainParts);
            }
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
            $pairedUrls = in_array($this->normalizeRedirect($app->redirect), ['www', 'non-www'], true)
                ? collect($newUrls)
                    ->map(fn (string $url) => $this->wwwCounterpartUrl($url))
                    ->filter()
                    ->values()
                    ->all()
                : [];
            $current = collect($this->splitDomains($app->fqdn));
            $currentCanonicalDomains = $current->map(
                fn (string $url): string => DomainPortOverrides::withoutPort($url)
            );
            foreach ($newUrls as $url) {
                if ($currentCanonicalDomains->contains(DomainPortOverrides::withoutPort($url))) {
                    $this->addError('newDomain', "Domain {$url} is already configured for this service.");

                    return;
                }
            }

            $merged = $current->merge($newUrls)->merge($pairedUrls)->unique()->values();
            $this->pendingAction = 'add';

            if (! $this->saveDomainListForApp($app, $merged)) {
                return;
            }

            $this->newDomain = '';
            $this->newDomainParts = DomainUrlParts::empty();
            $this->newDomainPartsChanged = false;
            $this->addDomainDnsFailed = false;
            $this->addDomainDnsMessage = '';
            $this->forceSaveDns = false;
            $this->forceSaveDomains = false;
            $this->forceRemovePort = false;
            $this->pendingAction = null;
            $this->dispatch('close-modal');
            $this->refreshDomains();
            $urlsToCheck = array_values(array_unique(array_merge($newUrls, $pairedUrls)));
            $serviceApplicationId = (int) $app->id;
            $dnsChecks = collect($urlsToCheck)->map(fn (string $url) => [
                'url' => $url,
                'check_id' => new_public_id(),
            ]);

            foreach ($dnsChecks as $dnsCheck) {
                $this->markUrlsAsChecking([$dnsCheck['url']], $serviceApplicationId, $dnsCheck['check_id']);
            }
            $this->persistAllDomainDnsStatuses();

            $failedDnsChecks = 0;
            foreach ($dnsChecks as $dnsCheck) {
                try {
                    CheckDomainDnsJob::dispatch(
                        $app,
                        $dnsCheck['url'],
                        $dnsCheck['url'],
                        $this->service->server,
                        $this->serverIp,
                        $dnsCheck['check_id'],
                    );
                } catch (\Throwable) {
                    $failedDnsChecks++;
                    $this->markUrlsDnsCheckUnavailable([$dnsCheck['url']], $serviceApplicationId, $dnsCheck['check_id']);
                }
            }

            if ($failedDnsChecks > 0) {
                $this->persistAllDomainDnsStatuses();
                $this->dispatch('error', 'Some DNS checks could not be started. Try again from the Domains page.');
            }

            $this->dispatch('success', $failedDnsChecks === $dnsChecks->count()
                ? 'Domain added.'
                : 'Domain added. DNS check started.');
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
    }

    /**
     * @param  array<int, string>  $urls
     */
    protected function markUrlsAsChecking(array $urls, int $serviceApplicationId, ?string $checkId = null): void
    {
        $indexesToCheck = [];

        foreach ($this->domainRows as $index => $row) {
            if (! in_array($row['url'], $urls, true)) {
                continue;
            }

            if ((int) ($row['service_application_id'] ?? 0) !== $serviceApplicationId) {
                continue;
            }

            $this->domainRows[$index]['dns_status'] = 'checking';
            $this->domainRows[$index]['dns_message'] = 'Checking DNS...';
            $this->domainRows[$index]['check_id'] = $checkId;
        }
    }

    /**
     * @param  array<int, string>  $urls
     */
    protected function markUrlsDnsCheckUnavailable(array $urls, int $serviceApplicationId, ?string $checkId = null): void
    {
        $this->markUrlsAsChecking($urls, $serviceApplicationId, $checkId);

        foreach ($this->domainRows as $index => $row) {
            if (! in_array($row['url'], $urls, true)) {
                continue;
            }

            if ((int) ($row['service_application_id'] ?? 0) !== $serviceApplicationId) {
                continue;
            }

            $this->domainRows[$index]['dns_status'] = 'skipped';
            $this->domainRows[$index]['dns_message'] = 'DNS check could not be started.';
            $this->domainRows[$index]['checked_at'] = now()->toIso8601String();
        }
    }

    public function startEdit(int $index): void
    {
        if (! isset($this->domainRows[$index]) || ($this->domainRows[$index]['is_suggested'] ?? false)) {
            return;
        }

        $this->editingIndex = $index;
        $this->editingDomain = $this->domainRows[$index]['url'];
        $this->editingDomainParts = DomainUrlParts::split($this->editingDomain);
        $internalPort = $this->domainRows[$index]['internal_port'] ?? null;
        if (filled($internalPort)) {
            $this->editingDomainParts['port'] = (string) $internalPort;
        }
        $this->editingDomainPartsChanged = false;
        $this->editingServiceApplicationId = (int) $this->domainRows[$index]['service_application_id'];
        $app = $this->findServiceApp($this->editingServiceApplicationId);
        $this->editingIndexing = $app?->isDomainNoindexed($this->editingDomain) ? 'noindex' : 'index';
        $this->editingRedirect = $this->serviceRedirectFor($this->editingServiceApplicationId);
        $this->editingOriginalRedirect = $this->editingRedirect;
        $this->editingDomainWasRegenerated = false;
        $this->editingGeneratedHost = null;
        $this->editDomainDnsFailed = false;
        $this->editDomainDnsMessage = '';
        $this->forceSaveEditDns = false;
        $this->resetErrorBag('editingDomain');
        $this->showEditDomainModal = true;
        $this->dispatch('open-edit-domain');
    }

    public function cancelEdit(): void
    {
        $this->showEditDomainModal = false;
        $this->editingIndex = null;
        $this->editingDomain = '';
        $this->editingDomainParts = DomainUrlParts::empty();
        $this->editingDomainPartsChanged = false;
        $this->editingServiceApplicationId = null;
        $this->editingIndexing = 'index';
        $this->editingRedirect = 'both';
        $this->editingOriginalRedirect = 'both';
        $this->editingDomainWasRegenerated = false;
        $this->editingGeneratedHost = null;
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

            $editingDomainParts = $this->editingDomainParts;
            $editingRow = $this->domainRows[$this->editingIndex];
            if (
                ! ($editingRow['has_port_override'] ?? false)
                && (string) ($editingDomainParts['port'] ?? '') === (string) ($editingRow['internal_port'] ?? '')
            ) {
                $editingDomainParts['port'] = '';
            }
            if ($this->editingDomainPartsChanged || filled($editingDomainParts['host'] ?? null)) {
                $this->editingDomain = DomainUrlParts::compose(...$editingDomainParts);
            }
            $this->validateOnly('editingDomain');
            $this->validateOnly('editingIndexing');
            $this->validateOnly('editingRedirect');

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
            if (blank(DomainUrlParts::split($newUrl)['port'] ?? null)) {
                $portOverrides = $app->domain_port_overrides ?? [];
                unset($portOverrides[DomainPortOverrides::withoutPort($oldUrl)]);
                unset($portOverrides[DomainPortOverrides::withoutPort($newUrl)]);
                $app->domain_port_overrides = $portOverrides ?: null;
            }

            $otherCanonicalDomains = $current
                ->reject(fn (string $url): bool => $url === $oldUrl)
                ->map(fn (string $url): string => DomainPortOverrides::withoutPort($url));
            if ($otherCanonicalDomains->contains(DomainPortOverrides::withoutPort($newUrl))) {
                $this->addError('editingDomain', "Domain {$newUrl} is already configured for this service.");

                return;
            }

            $replacements = [$oldUrl => $newUrl];
            if ($this->editingDomainWasRegenerated && filled($this->editingGeneratedHost) && in_array($this->editingRedirect, ['www', 'non-www'], true)) {
                $oldCounterpartHost = parse_url((string) $this->wwwCounterpartUrl($oldUrl, true), PHP_URL_HOST);
                $oldCounterpart = $current->first(fn (string $url): bool => parse_url($url, PHP_URL_HOST) === $oldCounterpartHost);
                if (is_string($oldCounterpart)) {
                    $parts = DomainUrlParts::split($oldCounterpart);
                    $port = ($app->domain_port_overrides ?? [])[DomainPortOverrides::withoutPort($oldCounterpart)] ?? null;
                    $parts['port'] = filled($port) ? (string) $port : $parts['port'];
                    $parts['host'] = str_starts_with(strtolower($parts['host']), 'www.') ? 'www.'.$this->editingGeneratedHost : $this->editingGeneratedHost;
                    $replacements[$oldCounterpart] = DomainUrlParts::compose(...$parts);
                }
            }
            $updated = $current->map(fn (string $url) => $replacements[$url] ?? $url)->unique()->values();
            if ($this->editingRedirect !== $this->editingOriginalRedirect && in_array($this->editingRedirect, ['www', 'non-www'], true)) {
                foreach ($updated->all() as $url) {
                    $counterpart = $this->wwwCounterpartUrl($url, true);
                    $host = is_string($counterpart) ? parse_url($counterpart, PHP_URL_HOST) : null;
                    if (filled($counterpart) && ! $updated->contains(fn (string $candidate): bool => parse_url($candidate, PHP_URL_HOST) === $host)) {
                        $updated->push($counterpart);
                    }
                }
            }
            $urlsToCheck = $updated
                ->reject(fn (string $url): bool => $current->contains(
                    fn (string $existingUrl): bool => ! DomainUrlParts::hasDnsRelevantChange($existingUrl, $url)
                ))
                ->map(fn (string $url): string => DomainPortOverrides::withoutPort($url))
                ->unique()
                ->values()
                ->all();
            $noindexDomains = $app->noindexDomains();
            foreach ($replacements as $previousUrl => $replacementUrl) {
                $isNoindexed = $previousUrl === $oldUrl ? $this->editingIndexing === 'noindex' : $app->isDomainNoindexed($previousUrl);
                $noindexDomains = $noindexDomains->reject(fn (string $domain): bool => $domain === $previousUrl);
                if ($isNoindexed) {
                    $noindexDomains->push($replacementUrl);
                }
            }
            $this->pendingAction = 'update';

            if (! $this->saveDomainListForApp($app, $updated, noindexDomains: $noindexDomains, redirect: $this->editingRedirect)) {
                return;
            }

            $this->cancelEdit();
            $this->dispatch('edit-domain-saved');
            $this->forceSaveDomains = false;
            $this->forceRemovePort = false;
            $this->pendingAction = null;
            $this->dispatch('success', 'Domain updated.');
            $this->refreshDomains();
            if ($urlsToCheck !== []) {
                $this->queueUrlsDns($urlsToCheck, $app);
            }
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
            if (! $this->saveDomainListForApp($app, $updated, checkConflicts: false)) {
                return;
            }

            $this->forceSaveDomains = false;
            $this->forceRemovePort = false;
            $this->dispatch('success', 'Domain removed.');
            $this->pruneDomainDnsStatusesToCurrentDomains();
            $this->refreshDomains();
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
    }

    public function removeDomainByKey(string $domainKey): void
    {
        $index = collect($this->domainRows)->search(
            fn (array $row): bool => ! ($row['is_suggested'] ?? false)
                && hash_equals($domainKey, $this->domainRowKey($row))
        );

        if ($index === false) {
            return;
        }

        $this->removeDomain((int) $index);
    }

    /**
     * @param  array{url: string, service_application_id: int|string}  $row
     */
    private function domainRowKey(array $row): string
    {
        return hash('sha256', $row['url'].'|'.$row['service_application_id']);
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

                    return;
                }
            }

            $merged = $current->merge($newUrls)->unique()->values();
            $this->pendingAction = 'suggested';
            $this->editingIndex = $index;

            if (! $this->saveDomainListForApp($app, $merged)) {
                return;
            }

            $this->forceAddSuggestedIndex = null;
            $this->editingIndex = null;
            $this->pendingAction = null;
            $this->forceSaveDomains = false;
            $this->forceRemovePort = false;
            $this->dispatch('success', 'Domain added.');
            $this->refreshDomains();
            $this->checkUrlsDns($newUrls, (int) $app->id);
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
                $parts = DomainUrlParts::split($domain);
                if ($parts['port'] === '') {
                    $domain = DomainUrlParts::compose($parts['scheme'], $parts['host'], (string) $requiredPort, $parts['path']);
                }
            }

            $this->newDomain = $domain;
            $this->newDomainParts = DomainUrlParts::split($domain);
            $this->newDomainPartsChanged = true;
            $this->updatedNewDomain();
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
    }

    public function regenerateEditingDomain(): void
    {
        $this->authorize('update', $this->service);
        if ($this->editingIndex === null || ! isset($this->domainRows[$this->editingIndex]) || ! $this->service->server) {
            return;
        }

        $host = parse_url(generateUrl(server: $this->service->server, random: new_public_id()), PHP_URL_HOST);
        if (! is_string($host) || $host === '') {
            return;
        }

        $this->editingGeneratedHost = $host;
        $this->editingDomainParts['host'] = str_starts_with(strtolower((string) $this->editingDomainParts['host']), 'www.') ? 'www.'.$host : $host;
        $this->editingDomainPartsChanged = true;
        $this->editingDomainWasRegenerated = true;
    }

    /**
     * @param  Collection<int, string>  $domains
     */
    protected function saveDomainListForApp(
        ServiceApplication $app,
        Collection $domains,
        bool $checkConflicts = true,
        bool $checkPorts = true,
        ?Collection $noindexDomains = null,
        ?string $redirect = null,
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
        if ($noindexDomains !== null) {
            $app->setNoindexDomains($noindexDomains);
        }
        if ($redirect !== null) {
            $app->redirect = $redirect;
        }

        if ($checkConflicts && ! $this->forceSaveDomains) {
            $result = checkDomainUsage(resource: $app);
            if ($result['hasConflicts']) {
                $this->domainConflicts = $result['conflicts'];
                $this->showDomainConflictModal = true;
                $app->refresh();

                return false;
            }
        }

        if ($checkPorts && ! $this->forceRemovePort) {
            $requiredPort = $app->getRequiredPort();
            if ($requiredPort !== null && $domainString) {
                $previousFqdn = $app->getOriginal('fqdn');
                foreach ($this->splitDomains($domainString) as $fqdn) {
                    if ($app->portRequiresConfirmation($fqdn, $requiredPort, is_string($previousFqdn) ? $previousFqdn : null)) {
                        $this->requiredPort = $requiredPort;
                        $this->showPortWarningModal = true;
                        $app->refresh();

                        return false;
                    }
                }
            }
        }

        $warning = sslipDomainWarning($domainString ?? '');
        if ($warning) {
            $this->dispatch('warning', __('warning.sslipdomain'));
        }

        DB::transaction(function () use ($app): void {
            $app->save();
            updateCompose($app);
            $this->service->parse();
        });

        if (str($app->fqdn)->contains(',')) {
            $this->dispatch('warning', 'Some services do not support multiple domains, which can lead to problems and is NOT RECOMMENDED.<br><br>Only use multiple domains if you know what you are doing.');
        }

        $this->dispatch('configurationChanged');

        return true;
    }

    /**
     * @param  array<int, string>  $urls
     */
    /**
     * Run a first-time DNS check for newly added/updated domain URLs and persist results.
     *
     * @param  array<int, string>  $urls
     */
    protected function checkUrlsDns(array $urls, ?int $serviceApplicationId = null): void
    {
        if ($urls === []) {
            return;
        }

        $urlSet = array_fill_keys($urls, true);
        $server = $this->service->server;
        $skipDns = ! $this->dnsValidationEnabled || ! $server;
        $indexesToCheck = [];

        foreach ($this->domainRows as $index => $row) {
            $url = $row['url'] ?? null;
            if (! is_string($url) || ! isset($urlSet[$url])) {
                continue;
            }

            if ($serviceApplicationId !== null
                && (int) ($row['service_application_id'] ?? 0) !== $serviceApplicationId) {
                continue;
            }

            if ($skipDns) {
                $this->domainRows[$index]['dns_status'] = 'skipped';
                $this->domainRows[$index]['dns_message'] = ! $this->dnsValidationEnabled
                    ? 'DNS validation is disabled in instance settings.'
                    : 'No server available for DNS validation.';
                $this->domainRows[$index]['checked_at'] = now()->toIso8601String();
                $this->domainRows[$index]['expected_ip'] = $this->serverIp;

                continue;
            }

            $indexesToCheck[] = $index;
        }

        if ($server && $indexesToCheck !== []) {
            $this->applyDnsStatuses($indexesToCheck, $server);
        }

        $this->persistAllDomainDnsStatuses();
    }

    /**
     * @param  array<int, string>  $urls
     */
    protected function queueUrlsDns(array $urls, ServiceApplication $application): void
    {
        foreach (array_unique($urls) as $url) {
            $checkId = new_public_id();
            $this->markUrlsAsChecking([$url], (int) $application->id, $checkId);
            $this->persistAllDomainDnsStatuses();

            try {
                CheckDomainDnsJob::dispatch(
                    $application,
                    $url,
                    $url,
                    $this->service->server,
                    $this->serverIp,
                    $checkId,
                );
            } catch (\Throwable) {
                $this->markUrlsDnsCheckUnavailable([$url], (int) $application->id, $checkId);
                $this->persistAllDomainDnsStatuses();
                $this->dispatch('error', 'The DNS check could not be started. Try again from the Domains page.');
            }
        }
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

        $results = CheckDomainDns::run(array_combine($urls, $urls), $server, $this->serverIp);

        foreach ($results as $result) {
            if ($result['status'] === 'failed') {
                return $result['message'];
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

    /**
     * @param  bool  $forRedirectPairing  When true, also pair sslip/nip hosts for www↔non-www redirects.
     */
    protected function wwwCounterpartUrl(string $url, bool $forRedirectPairing = false): ?string
    {
        $host = $this->domainHost($url);
        if ($host === null) {
            return null;
        }

        $lowerHost = strtolower($host);

        if (filter_var($host, FILTER_VALIDATE_IP) !== false || $lowerHost === 'localhost') {
            return null;
        }

        if (
            ! $forRedirectPairing
            && (str_contains($lowerHost, 'sslip.io') || str_contains($lowerHost, 'nip.io'))
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
