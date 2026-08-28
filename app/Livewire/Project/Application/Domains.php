<?php

namespace App\Livewire\Project\Application;

use App\Actions\Shared\CheckDomainDns;
use App\Jobs\CheckDomainDnsJob;
use App\Livewire\Concerns\InteractsWithCloudflareDomainConnect;
use App\Livewire\Project\Shared\ConfigurationChecker;
use App\Models\Application;
use App\Models\Server;
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

    public Application $application;

    public string $redirect = 'both';

    public bool $isForceHttpsEnabled = true;

    /**
     * Per compose-service www/non-www redirect direction.
     * Keys are wire-safe (dots encoded) — use serviceRedirectWireKey().
     *
     * @var array<string, string>
     */
    public array $serviceRedirects = [];

    /** Compose service name when a pending domain conflict belongs to setServiceRedirect. */
    public ?string $pendingRedirectService = null;

    public string $newDomain = '';

    public array $newDomainParts = ['scheme' => 'https', 'host' => '', 'port' => '', 'path' => ''];

    public bool $newDomainPartsChanged = false;

    public ?string $newDomainService = null;

    public ?int $editingIndex = null;

    public string $editingDomain = '';

    public array $editingDomainParts = ['scheme' => 'https', 'host' => '', 'port' => '', 'path' => ''];

    public bool $editingDomainPartsChanged = false;

    public ?string $editingService = null;

    /** @var array<int, array{url: string, service: ?string, dns_status: string, dns_message: string, expected_ip: ?string, checked_at?: ?string, is_suggested?: bool, suggested_for?: ?string, suggestion_label?: ?string, needs_force_add?: bool}> */
    public array $domainRows = [];

    /** When set, the next addSuggestedDomain call for this index skips the DNS block. */
    public ?int $forceAddSuggestedIndex = null;

    /** @var array<int, string> */
    public array $composeServices = [];

    public array $domainConflicts = [];

    public bool $showDomainConflictModal = false;

    public bool $forceSaveDomains = false;

    public bool $forceSaveDns = false;

    public bool $addDomainDnsFailed = false;

    public string $addDomainDnsMessage = '';

    public bool $showEditDomainModal = false;

    public bool $forceSaveEditDns = false;

    public bool $editDomainDnsFailed = false;

    public string $editDomainDnsMessage = '';

    /** Pending save path after conflict confirmation: add | update | suggested | redirect */
    public ?string $pendingAction = null;

    public bool $isCompose = false;

    public bool $labelsAreWritable = false;

    public bool $isCheckingDns = false;

    public bool $dnsValidationEnabled = true;

    /** Resolved or literal IP users should point DNS at. */
    public ?string $serverIp = null;

    /** Raw server IP/hostname as configured (may be a hostname). */
    public ?string $serverIpConfigured = null;

    protected $listeners = [
        'configurationChanged' => 'refreshDomains',
        'confirmDomainUsage',
    ];

    protected function rules(): array
    {
        return [
            'newDomain' => ValidationPatterns::applicationDomainRules(),
            'editingDomain' => ValidationPatterns::applicationDomainRules(),
            'redirect' => 'string|required|in:both,www,non-www',
            'isForceHttpsEnabled' => 'boolean',
            'serviceRedirects' => 'array',
            'serviceRedirects.*' => 'string|in:both,www,non-www',
        ];
    }

    protected function messages(): array
    {
        return array_merge(
            ValidationPatterns::combinedMessages(),
            [
                'redirect.required' => 'The Redirect setting is required.',
                'redirect.string' => 'The Redirect setting must be a string.',
                'redirect.in' => 'The Redirect setting must be both, www, or non-www.',
                'serviceRedirects.*.in' => 'The Redirect setting must be both, www, or non-www.',
            ]
        );
    }

    public function mount(): void
    {
        $this->authorize('view', $this->application);
        $this->loadDomainState();
    }

    public function refreshDomains(): void
    {
        $this->loadDomainState();
    }

    public function pollDnsChecks(): void
    {
        $this->authorize('view', $this->application);

        $checkingRows = collect($this->domainRows)
            ->where('dns_status', 'checking')
            ->values();

        $this->refreshDomains();

        foreach ($checkingRows as $checkingRow) {
            $row = collect($this->domainRows)->first(fn (array $row): bool => $row['url'] === $checkingRow['url']
                && ($row['service'] ?? null) === ($checkingRow['service'] ?? null));

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

    public function toggleNoindexDomain(string $domain, string|bool $indexing): void
    {
        $this->authorize('update', $this->application);

        $noindex = $indexing === true || $indexing === 'noindex';
        $domains = $this->application->noindexDomains();
        $domains = $noindex ? $domains->push($domain) : $domains->reject(fn (string $item) => $item === $domain);

        $this->application->setNoindexDomains($domains);
        $this->application->save();
        $this->application->refresh();
        $this->resetDefaultLabels();
        $this->dispatch('configurationChanged')->to(ConfigurationChecker::class);
        $this->dispatch('success', 'Search engine indexing updated.');
    }

    public function updateRedirect(string $redirect): void
    {
        $this->redirect = $redirect;
        $this->setRedirect();
    }

    public function updateForceHttps(): void
    {
        $this->authorize('update', $this->application);
        $this->validateOnly('isForceHttpsEnabled');

        $this->application->settings->is_force_https_enabled = $this->isForceHttpsEnabled;
        $this->application->settings->save();
        $this->resetDefaultLabels();
        $this->dispatch('configurationChanged')->to(ConfigurationChecker::class);
        $this->dispatch('success', 'HTTP to HTTPS redirect updated.');
    }

    public function loadDomainState(): void
    {
        $this->application->refresh();
        $this->application->loadMissing(['destination.server', 'settings', 'additional_servers']);

        $this->isCompose = $this->application->build_pack === 'dockercompose';
        $this->labelsAreWritable = $this->application->settings->is_container_label_readonly_enabled === false;
        $this->redirect = $this->application->redirect ?? 'both';
        $this->isForceHttpsEnabled = $this->application->isForceHttpsEnabled();

        $settings = instanceSettings();
        $this->dnsValidationEnabled = (bool) data_get($settings, 'is_dns_validation_enabled', true);

        $server = $this->application->destination?->server;
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
            // Prefer real IP so users know what DNS record to set even when the
            // server address is stored as a hostname (e.g. coolify-testing-host).
            $this->serverIp = $resolved['ip'] ?? $resolved['configured'];
        } else {
            $this->serverIp = null;
            $this->serverIpConfigured = null;
        }

        $this->composeServices = [];
        $this->serviceRedirects = [];
        if ($this->isCompose) {
            try {
                $parsed = $this->application->parse() ?? [];
            } catch (\Throwable) {
                $parsed = [];
            }
            $services = data_get($parsed, 'services', []);
            foreach ($services as $serviceName => $service) {
                if (! isDatabaseImage(data_get($service, 'image'))) {
                    $this->composeServices[] = $serviceName;
                }
            }
            if ($this->newDomainService === null && count($this->composeServices) > 0) {
                $this->newDomainService = $this->composeServices[0];
            }

            $domains = $this->application->docker_compose_domains
                ? json_decode($this->application->docker_compose_domains, true)
                : [];
            if (! is_array($domains)) {
                $domains = [];
            }

            $serviceNames = $this->composeServices;
            foreach (array_keys($domains) as $serviceName) {
                if (! in_array($serviceName, $serviceNames, true)) {
                    $serviceNames[] = $serviceName;
                }
            }

            foreach ($serviceNames as $serviceName) {
                // data_get treats dots as path separators; read the service entry by array key.
                $serviceEntry = $domains[$serviceName] ?? null;
                $storedRedirect = is_array($serviceEntry) ? ($serviceEntry['redirect'] ?? null) : null;
                $this->serviceRedirects[$this->serviceRedirectWireKey($serviceName)] = $this->normalizeRedirect(
                    is_string($storedRedirect) ? $storedRedirect : null
                );
            }
        }

        // Do not auto-promote www/non-www pairs here: load/refresh also runs after
        // removeDomain, and re-adding counterparts would undo intentional deletes.
        // Pairs are still ensured on setRedirect, addDomain, and generateDomain.

        $this->domainRows = $this->buildDomainRows();
    }

    /**
     * Livewire wire:model treats dots as nesting (serviceRedirects.api.test).
     * Encode them so compose service names like "api.test" stay flat string values.
     */
    public function serviceRedirectWireKey(string $serviceName): string
    {
        return str_replace('.', '__dot__', $serviceName);
    }

    protected function normalizeRedirect(?string $redirect): string
    {
        return in_array($redirect, ['www', 'non-www', 'both'], true) ? $redirect : 'both';
    }

    protected function serviceRedirectFor(?string $serviceName): string
    {
        if ($serviceName === null) {
            return $this->normalizeRedirect($this->redirect);
        }

        return $this->normalizeRedirect(
            $this->serviceRedirects[$this->serviceRedirectWireKey($serviceName)] ?? null
        );
    }

    /**
     * @return array<int, array{url: string, service: ?string, dns_status: string, dns_message: string, expected_ip: ?string, checked_at: ?string, is_suggested: bool, suggested_for: ?string, suggestion_label: ?string, needs_force_add: bool}>
     */
    protected function buildDomainRows(): array
    {
        $rows = [];
        $stored = $this->storedDomainDnsStatuses();

        if ($this->isCompose) {
            $domains = $this->application->docker_compose_domains
                ? json_decode($this->application->docker_compose_domains, true)
                : [];

            if (! is_array($domains)) {
                $domains = [];
            }

            $serviceNames = $this->composeServices;
            foreach (array_keys($domains) as $serviceName) {
                if (! in_array($serviceName, $serviceNames, true)) {
                    $serviceNames[] = $serviceName;
                }
            }

            foreach ($serviceNames as $serviceName) {
                $configured = [];
                // Array key access: data_get() would treat dots in service names as path separators.
                $domainString = is_array($domains[$serviceName] ?? null)
                    ? ($domains[$serviceName]['domain'] ?? null)
                    : null;
                foreach ($this->splitDomains(is_string($domainString) ? $domainString : null) as $url) {
                    $row = $this->domainRowFromStored($url, $serviceName, $stored);
                    $rows[] = $row;
                    $configured[] = $row;
                }

            }

            return $this->sortDomainRowsByDnsStatus($rows);
        }

        foreach ($this->splitDomains($this->application->fqdn) as $url) {
            $rows[] = $this->domainRowFromStored($url, null, $stored);
        }

        return $this->sortDomainRowsByDnsStatus($rows);
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    protected function sortDomainRowsByDnsStatus(array $rows): array
    {
        return collect($rows)
            ->sortBy(fn (array $row): int => ($row['dns_status'] ?? null) === 'failed' ? 0 : 1)
            ->values()
            ->all();
    }

    /**
     * Missing www/non-www counterparts as full domain rows (DNS-checkable, not yet saved).
     *
     * Messaging depends on Direction:
     * - both: both hosts are first-class and need DNS to the server
     * - www / non-www: the missing pair is for Coolify's redirect (still must resolve to
     *   the server so the proxy can redirect; not framed as a separate primary site)
     *
     * @param  array<int, array<string, mixed>>  $configuredRows
     * @param  array<string, array{status?: string, message?: string, expected_ip?: ?string, checked_at?: ?string}>  $stored
     * @return array<int, array{url: string, service: ?string, dns_status: string, dns_message: string, expected_ip: ?string, checked_at: ?string, is_suggested: bool, suggested_for: ?string, suggestion_label: ?string, needs_force_add: bool, suggestion_role: ?string}>
     */
    protected function buildSuggestedWwwRows(array $configuredRows, array $stored, ?string $service = null): array
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
            $rowService = $service ?? ($row['service'] ?? null);
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

            $base = $this->domainRowFromStored($counterpart, $rowService, $stored);
            $isWww = str_starts_with($hostKey, 'www.');
            $meta = $this->suggestedDomainMeta($isWww, $this->serviceRedirectFor(is_string($rowService) ? $rowService : null));

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
     * Labels/copy for a suggested www or non-www host based on Direction.
     *
     * @return array{label: string, role: string, pending_message: string, dns_suffix: string}
     */
    protected function suggestedDomainMeta(bool $suggestedIsWww, ?string $redirectOverride = null): array
    {
        $pendingMessage = 'Not configured yet.';
        $redirect = $redirectOverride ?? ($this->redirect ?: 'both');

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

    /**
     * @param  array<string, array{status?: string, message?: string, expected_ip?: ?string, checked_at?: ?string}>  $stored
     * @return array{url: string, service: ?string, dns_status: string, dns_message: string, expected_ip: ?string, checked_at: ?string, is_suggested: bool, suggested_for: ?string, suggestion_label: ?string, needs_force_add: bool}
     */
    protected function domainRowFromStored(string $url, ?string $service, array $stored): array
    {
        $key = $this->domainDnsStatusKey($url, $service);
        $entry = $stored[$key] ?? null;

        if (is_array($entry) && filled(data_get($entry, 'status'))) {
            return [
                'url' => $url,
                'service' => $service,
                'dns_status' => (string) data_get($entry, 'status', 'pending'),
                'dns_message' => (string) data_get($entry, 'message', 'Not checked yet.'),
                'expected_ip' => data_get($entry, 'expected_ip') ?: $this->serverIp,
                'checked_at' => data_get($entry, 'checked_at'),
                'check_id' => data_get($entry, 'check_id'),
                'is_suggested' => false,
                'suggested_for' => null,
                'suggestion_label' => null,
                'needs_force_add' => false,
            ];
        }

        return [
            'url' => $url,
            'service' => $service,
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
    }

    /**
     * @return array<string, array{status: string, message: string, expected_ip: ?string, checked_at: ?string}>
     */
    protected function storedDomainDnsStatuses(): array
    {
        $stored = $this->application->domain_dns_statuses;

        return is_array($stored) ? $stored : [];
    }

    protected function domainDnsStatusKey(string $url, ?string $service = null): string
    {
        return $service ? "{$service}|{$url}" : $url;
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

    protected function authorizeUpdateForDomainConnect(): void
    {
        $this->authorize('update', $this->application);
    }

    public function checkAllDns(): void
    {
        $this->authorize('update', $this->application);

        $this->isCheckingDns = true;

        try {
            $server = $this->application->destination?->server;
            $skipDns = ! $this->dnsValidationEnabled
                || ! $server
                || $this->application->additional_servers->count() > 0;

            $indexesToCheck = [];

            foreach ($this->domainRows as $index => $row) {
                if ($skipDns) {
                    $reason = ! $this->dnsValidationEnabled
                        ? 'DNS validation is disabled in instance settings.'
                        : ($this->application->additional_servers->count() > 0
                            ? 'DNS check skipped for multi-server applications.'
                            : 'No server available for DNS validation.');

                    $this->domainRows[$index]['dns_status'] = 'skipped';
                    $this->domainRows[$index]['dns_message'] = $reason;
                    $this->domainRows[$index]['checked_at'] = now()->toIso8601String();

                    continue;
                }

                $indexesToCheck[] = $index;
            }

            if ($server && $indexesToCheck !== []) {
                $this->applyDnsStatuses($indexesToCheck, $server);
            }

            $this->persistDomainDnsStatuses();
        } finally {
            $this->isCheckingDns = false;
        }
    }

    public function checkDomainDns(int $index): void
    {
        $this->authorize('update', $this->application);

        if (! isset($this->domainRows[$index])) {
            return;
        }

        $server = $this->application->destination?->server;
        if (! $server || ! $this->dnsValidationEnabled || $this->application->additional_servers->count() > 0) {
            $this->domainRows[$index]['dns_status'] = 'skipped';
            $this->domainRows[$index]['dns_message'] = 'DNS check skipped.';
            $this->domainRows[$index]['checked_at'] = now()->toIso8601String();
            $this->persistDomainDnsStatuses();

            return;
        }

        $this->applyDnsStatus($index, $server);
        $this->persistDomainDnsStatuses();
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

            // Keep suggested-row copy short after DNS checks (no role badge).
            if ($this->domainRows[$index]['is_suggested'] ?? false) {
                $isWww = str_starts_with(strtolower((string) $this->domainHost((string) $this->domainRows[$index]['url'])), 'www.');
                $serviceName = $this->domainRows[$index]['service'] ?? null;
                $meta = $this->suggestedDomainMeta(
                    $isWww,
                    $this->serviceRedirectFor(is_string($serviceName) ? $serviceName : null)
                );
                $this->domainRows[$index]['dns_message'] = $meta['pending_message'];
                $this->domainRows[$index]['suggestion_label'] = null;
                $this->domainRows[$index]['suggestion_role'] = $meta['role'];
            }

            $this->domainRows[$index]['expected_ip'] = $result['expected_ip'];
            $this->domainRows[$index]['checked_at'] = $result['checked_at'];
            $this->domainRows[$index]['check_id'] = null;
        }
    }

    /**
     * Persist current domain row DNS results and drop statuses for removed domains.
     */
    protected function persistDomainDnsStatuses(): void
    {
        $statuses = [];

        foreach ($this->domainRows as $row) {
            $url = $row['url'] ?? null;
            if (! is_string($url) || $url === '') {
                continue;
            }

            $status = $row['dns_status'] ?? 'pending';
            if ($status === 'pending') {
                // Keep previously stored value for unchecked domains if present.
                $key = $this->domainDnsStatusKey($url, $row['service'] ?? null);
                $existing = data_get($this->storedDomainDnsStatuses(), $key);
                if (is_array($existing) && filled(data_get($existing, 'status'))) {
                    $statuses[$key] = $existing;
                }

                continue;
            }

            $key = $this->domainDnsStatusKey($url, $row['service'] ?? null);
            $statuses[$key] = [
                'status' => $status,
                'message' => (string) ($row['dns_message'] ?? ''),
                'expected_ip' => $row['expected_ip'] ?? $this->serverIp,
                'checked_at' => $row['checked_at'] ?? now()->toIso8601String(),
                'check_id' => $row['check_id'] ?? null,
            ];
        }

        DB::transaction(function () use (&$statuses): void {
            $application = Application::query()->lockForUpdate()->findOrFail($this->application->id);
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

        $this->application->domain_dns_statuses = $statuses === [] ? null : $statuses;
    }

    protected function pruneDomainDnsStatusesToCurrentDomains(): void
    {
        $this->application->refresh();
        // Rebuild rows from current domain list, keep only matching stored keys.
        $this->domainRows = $this->buildDomainRows();
        $this->persistDomainDnsStatuses();
    }

    /**
     * Human-readable DNS target: real IP, with configured hostname when it differs.
     */
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

    public function updatedNewDomain(): void
    {
        $this->resetAddDomainDnsGate();
    }

    public function updatedNewDomainParts(): void
    {
        $this->newDomainPartsChanged = true;
        $this->resetAddDomainDnsGate();
    }

    public function updatedNewDomainService(): void
    {
        $this->resetAddDomainDnsGate();
    }

    public function resetAddDomainDnsGate(): void
    {
        $this->addDomainDnsFailed = false;
        $this->addDomainDnsMessage = '';
        $this->forceSaveDns = false;
    }

    public function resetAddDomainForm(): void
    {
        $this->newDomain = '';
        $this->newDomainParts = DomainUrlParts::empty();
        $this->newDomainPartsChanged = false;
        $this->resetAddDomainDnsGate();
        $this->resetErrorBag('newDomain');
    }

    public function confirmAddDomainDespiteDns(): void
    {
        $this->forceSaveDns = true;
        $this->addDomain();
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

        if ($this->pendingAction === 'redirect') {
            if ($this->isCompose && filled($this->pendingRedirectService)) {
                $this->setServiceRedirect($this->pendingRedirectService);

                return;
            }

            $this->setRedirect();

            return;
        }

        $this->addDomain();
    }

    /**
     * Clear pending conflict state when the modal is dismissed without confirmation.
     * confirmDomainUsage sets forceSaveDomains before closing the modal.
     */
    public function updatedShowDomainConflictModal(bool $value): void
    {
        if ($value || $this->forceSaveDomains) {
            return;
        }

        $this->pendingAction = null;
    }

    public function addDomain(): void
    {
        try {
            $this->authorize('update', $this->application);

            if ($this->labelsAreWritable) {
                $this->dispatch('error', 'Domains cannot be edited while container labels are writable. Set domains in the Labels section on General.');

                return;
            }

            if ($this->newDomainPartsChanged) {
                $this->newDomain = DomainUrlParts::compose(...$this->newDomainParts);
            }
            $this->validateOnly('newDomain');

            $normalized = ValidationPatterns::normalizeApplicationDomains($this->newDomain);
            if (blank($normalized)) {
                $this->addError('newDomain', 'Please enter a valid domain URL.');

                return;
            }

            $newUrls = $this->splitDomains($normalized);
            $pairedUrls = collect($newUrls)
                ->map(fn (string $url) => $this->wwwCounterpartUrl($url))
                ->filter()
                ->values()
                ->all();
            $current = $this->currentDomainList($this->newDomainService);

            foreach ($newUrls as $url) {
                if ($current->contains($url)) {
                    $this->addError('newDomain', "Domain {$url} is already configured.");

                    return;
                }
            }

            $merged = $current->merge($newUrls)->merge($pairedUrls)->unique()->values();
            $this->pendingAction = 'add';
            if (! $this->saveDomainList($merged, $this->newDomainService)) {
                return;
            }

            $this->forceSaveDomains = false;
            $this->pendingAction = null;
            $serviceForCheck = $this->newDomainService;
            $this->resetAddDomainForm();
            $this->dispatch('close-modal');
            $this->refreshDomains();
            $urlsToCheck = array_values(array_unique(array_merge($newUrls, $pairedUrls)));
            $dnsChecks = collect($this->dnsEntriesForUrls($urlsToCheck, $serviceForCheck))
                ->map(fn (string $url, string $statusKey) => [
                    'status_key' => $statusKey,
                    'url' => $url,
                    'check_id' => new_public_id(),
                ]);

            foreach ($dnsChecks as $dnsCheck) {
                $this->markUrlsAsChecking([$dnsCheck['url']], $serviceForCheck, $dnsCheck['check_id']);
            }
            $this->persistDomainDnsStatuses();

            $failedDnsChecks = 0;
            foreach ($dnsChecks as $dnsCheck) {
                try {
                    CheckDomainDnsJob::dispatch(
                        $this->application,
                        $dnsCheck['status_key'],
                        $dnsCheck['url'],
                        $this->application->destination?->server,
                        $this->serverIp,
                        $dnsCheck['check_id'],
                        $this->application->additional_servers->count() > 0,
                    );
                } catch (\Throwable) {
                    $failedDnsChecks++;
                    $this->markUrlsDnsCheckUnavailable([$dnsCheck['url']], $serviceForCheck, $dnsCheck['check_id']);
                }
            }

            if ($failedDnsChecks > 0) {
                $this->persistDomainDnsStatuses();
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
    protected function markUrlsAsChecking(array $urls, ?string $service = null, ?string $checkId = null): void
    {
        $indexesToCheck = [];

        foreach ($this->domainRows as $index => $row) {
            if (! in_array($row['url'], $urls, true)) {
                continue;
            }

            if ($service !== null && ($row['service'] ?? null) !== $service) {
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
    protected function markUrlsDnsCheckUnavailable(array $urls, ?string $service = null, ?string $checkId = null): void
    {
        $this->markUrlsAsChecking($urls, $service, $checkId);

        foreach ($this->domainRows as $index => $row) {
            if (! in_array($row['url'], $urls, true)) {
                continue;
            }

            if ($service !== null && ($row['service'] ?? null) !== $service) {
                continue;
            }

            $this->domainRows[$index]['dns_status'] = 'skipped';
            $this->domainRows[$index]['dns_message'] = 'DNS check could not be started.';
            $this->domainRows[$index]['checked_at'] = now()->toIso8601String();
        }
    }

    /**
     * @param  array<int, string>  $urls
     * @return array<string, string>
     */
    protected function dnsEntriesForUrls(array $urls, ?string $service = null): array
    {
        $entries = [];

        foreach ($urls as $url) {
            $entries[$this->domainDnsStatusKey($url, $service)] = $url;
        }

        return $entries;
    }

    /**
     * Run a first-time DNS check for newly added/updated domain URLs and persist results.
     *
     * @param  array<int, string>  $urls
     */
    protected function checkUrlsDns(array $urls, ?string $service = null): void
    {
        if ($urls === []) {
            return;
        }

        $urlSet = array_fill_keys($urls, true);
        $server = $this->application->destination?->server;
        $skipDns = ! $this->dnsValidationEnabled
            || ! $server
            || $this->application->additional_servers->count() > 0;

        foreach ($this->domainRows as $index => $row) {
            $url = $row['url'] ?? null;
            if (! is_string($url) || ! isset($urlSet[$url])) {
                continue;
            }

            if ($service !== null && ($row['service'] ?? null) !== $service) {
                continue;
            }

            if ($skipDns) {
                $reason = ! $this->dnsValidationEnabled
                    ? 'DNS validation is disabled in instance settings.'
                    : ($this->application->additional_servers->count() > 0
                        ? 'DNS check skipped for multi-server applications.'
                        : 'No server available for DNS validation.');

                $this->domainRows[$index]['dns_status'] = 'skipped';
                $this->domainRows[$index]['dns_message'] = $reason;
                $this->domainRows[$index]['checked_at'] = now()->toIso8601String();
                $this->domainRows[$index]['expected_ip'] = $this->serverIp;

                continue;
            }

            $indexesToCheck[] = $index;
        }

        if ($server && $indexesToCheck !== []) {
            $this->applyDnsStatuses($indexesToCheck, $server);
        }

        $this->persistDomainDnsStatuses();
    }

    protected function shouldValidateDnsForAdd(): bool
    {
        if (! $this->dnsValidationEnabled) {
            return false;
        }

        $server = $this->application->destination?->server;
        if (! $server) {
            return false;
        }

        if ($this->application->additional_servers->count() > 0) {
            return false;
        }

        return true;
    }

    /**
     * @param  array<int, string>  $urls
     */
    protected function findDnsFailureMessage(array $urls): ?string
    {
        $server = $this->application->destination?->server;
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

    public function updatedEditingDomain(): void
    {
        $this->resetEditDomainDnsGate();
    }

    public function updatedEditingDomainParts(): void
    {
        $this->editingDomainPartsChanged = true;
        $this->resetEditDomainDnsGate();
    }

    public function resetEditDomainDnsGate(): void
    {
        $this->editDomainDnsFailed = false;
        $this->editDomainDnsMessage = '';
        $this->forceSaveEditDns = false;
    }

    public function startEdit(int $index): void
    {
        if (! isset($this->domainRows[$index]) || ($this->domainRows[$index]['is_suggested'] ?? false)) {
            return;
        }

        $this->editingIndex = $index;
        $this->editingDomain = $this->domainRows[$index]['url'];
        $this->editingDomainParts = DomainUrlParts::split($this->editingDomain);
        $this->editingDomainPartsChanged = false;
        $this->editingService = $this->domainRows[$index]['service'];
        $this->resetEditDomainDnsGate();
        $this->resetErrorBag('editingDomain');
        $this->showEditDomainModal = true;
        $this->dispatch('open-edit-domain');
    }

    public function addSuggestedDomain(int $index): void
    {
        try {
            $this->authorize('update', $this->application);

            if ($this->labelsAreWritable) {
                $this->dispatch('error', 'Domains cannot be edited while container labels are writable.');

                return;
            }

            if (! isset($this->domainRows[$index]) || ! ($this->domainRows[$index]['is_suggested'] ?? false)) {
                return;
            }

            $url = (string) $this->domainRows[$index]['url'];
            $normalized = ValidationPatterns::normalizeApplicationDomains($url);
            if (blank($normalized)) {
                $this->dispatch('error', 'Invalid suggested domain.');

                return;
            }

            $serviceName = $this->domainRows[$index]['service'] ?? null;
            $newUrls = $this->splitDomains($normalized);
            $current = $this->currentDomainList($serviceName);
            foreach ($newUrls as $newUrl) {
                if ($current->contains($newUrl)) {
                    $this->refreshDomains();

                    return;
                }
            }

            $force = $this->forceAddSuggestedIndex === $index
                || (bool) ($this->domainRows[$index]['needs_force_add'] ?? false);

            if (! $force && $this->shouldValidateDnsForAdd()) {
                $dnsFailure = $this->findDnsFailureMessage($newUrls);
                if ($dnsFailure !== null) {
                    $this->domainRows[$index]['dns_status'] = 'failed';
                    $this->domainRows[$index]['dns_message'] = $dnsFailure;
                    $this->domainRows[$index]['checked_at'] = now()->toIso8601String();
                    $this->domainRows[$index]['needs_force_add'] = true;
                    $this->forceAddSuggestedIndex = $index;
                    $this->editingIndex = $index;
                    $this->persistDomainDnsStatuses();

                    return;
                }
            }

            $merged = $current->merge($newUrls)->unique()->values();
            $this->pendingAction = 'suggested';
            $this->editingIndex = $index;
            if (! $this->saveDomainList($merged, $serviceName)) {
                return;
            }

            $this->forceAddSuggestedIndex = null;
            $this->editingIndex = null;
            $this->pendingAction = null;
            $this->forceSaveDomains = false;
            $this->dispatch('success', 'Domain added.');
            $this->refreshDomains();
            $this->checkUrlsDns($newUrls, $serviceName);
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
    }

    public function cancelEdit(): void
    {
        $this->showEditDomainModal = false;
        $this->editingIndex = null;
        $this->editingDomain = '';
        $this->editingDomainParts = DomainUrlParts::empty();
        $this->editingDomainPartsChanged = false;
        $this->editingService = null;
        $this->resetEditDomainDnsGate();
        $this->resetErrorBag('editingDomain');
        if ($this->pendingAction === 'update') {
            $this->pendingAction = null;
            $this->forceSaveDomains = false;
            $this->showDomainConflictModal = false;
        }
    }

    public function confirmUpdateDomainDespiteDns(): void
    {
        $this->forceSaveEditDns = true;
        $this->updateDomain();
    }

    public function updateDomain(): void
    {
        try {
            $this->authorize('update', $this->application);

            if ($this->labelsAreWritable) {
                $this->dispatch('error', 'Domains cannot be edited while container labels are writable.');

                return;
            }

            if ($this->editingIndex === null || ! isset($this->domainRows[$this->editingIndex])) {
                return;
            }

            if ($this->editingDomainPartsChanged) {
                $this->editingDomain = DomainUrlParts::compose(...$this->editingDomainParts);
            }
            $this->validateOnly('editingDomain');

            $normalized = ValidationPatterns::normalizeApplicationDomains($this->editingDomain);
            if (blank($normalized) || count($this->splitDomains($normalized)) !== 1) {
                $this->addError('editingDomain', 'Please enter a single valid domain URL.');

                return;
            }

            $newUrl = $this->splitDomains($normalized)[0];
            $oldUrl = $this->domainRows[$this->editingIndex]['url'];
            $service = $this->editingService;
            $wasNoindexed = $this->application->isDomainNoindexed($oldUrl);

            $current = $this->currentDomainList($service);
            if ($newUrl !== $oldUrl && $current->contains($newUrl)) {
                $this->addError('editingDomain', "Domain {$newUrl} is already configured.");

                return;
            }

            if (! $this->forceSaveEditDns && $this->shouldValidateDnsForAdd()) {
                $dnsFailure = $this->findDnsFailureMessage([$newUrl]);
                if ($dnsFailure !== null) {
                    $this->editDomainDnsFailed = true;
                    $this->editDomainDnsMessage = str_replace('add it anyway', 'save it anyway', $dnsFailure);
                    $this->showEditDomainModal = true;

                    return;
                }
            }

            $updated = $current->map(fn (string $url) => $url === $oldUrl ? $newUrl : $url)->unique()->values();
            $this->pendingAction = 'update';
            if (! $this->saveDomainList($updated, $service)) {
                return;
            }

            $noindexDomains = $this->application->noindexDomains()->reject(fn (string $domain) => $domain === $oldUrl);
            if ($wasNoindexed) {
                $noindexDomains->push($newUrl);
            }
            $this->application->setNoindexDomains($noindexDomains);
            $this->application->save();
            $this->resetDefaultLabels();

            $this->forceSaveDomains = false;
            $this->pendingAction = null;
            $this->cancelEdit();
            $this->dispatch('edit-domain-saved');
            $this->dispatch('success', 'Domain updated.');
            $this->refreshDomains();
            $this->checkUrlsDns([$newUrl], $service);
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
    }

    public function removeDomain(int $index): void
    {
        try {
            $this->authorize('update', $this->application);

            if ($this->labelsAreWritable) {
                $this->dispatch('error', 'Domains cannot be edited while container labels are writable.');

                return;
            }

            if (! isset($this->domainRows[$index]) || ($this->domainRows[$index]['is_suggested'] ?? false)) {
                return;
            }

            $url = $this->domainRows[$index]['url'];
            $service = $this->domainRows[$index]['service'];
            $updated = $this->currentDomainList($service)->reject(fn (string $item) => $item === $url)->values();

            if (! $this->saveDomainList($updated, $service, checkConflicts: false)) {
                return;
            }

            if ($this->editingIndex === $index) {
                $this->cancelEdit();
            }

            $this->dispatch('success', 'Domain removed.');
            $this->refreshDomains();
            $this->pruneDomainDnsStatusesToCurrentDomains();
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
     * @param  array{url: string, service?: ?string}  $row
     */
    private function domainRowKey(array $row): string
    {
        return hash('sha256', $row['url'].'|'.($row['service'] ?? ''));
    }

    public function generateDomain(?string $serviceName = null): void
    {
        try {
            $this->authorize('update', $this->application);

            if ($this->labelsAreWritable) {
                $this->dispatch('error', 'Domains cannot be edited while container labels are writable.');

                return;
            }

            $server = data_get($this->application, 'destination.server');
            if (! $server) {
                $this->dispatch('error', 'No server found for this application.');

                return;
            }

            if ($this->isCompose) {
                $serviceName = $serviceName ?: $this->newDomainService ?: ($this->composeServices[0] ?? null);
                if (! $serviceName) {
                    $this->dispatch('error', 'No compose service available for domain generation.');

                    return;
                }

                $domain = generateUrl(server: $server, random: new_public_id());
                $current = $this->currentDomainList($serviceName);
                $merged = $current->push($domain)->unique()->values();

                if (! $this->saveDomainList($merged, $serviceName, checkConflicts: false)) {
                    return;
                }

                $pairedUrls = $this->syncRedirectDomainPairs($serviceName);
                $this->resetAddDomainForm();
                $this->dispatch('close-modal');
                $this->dispatch('success', 'Domain generated.');
                $this->refreshDomains();
                $this->checkUrlsDns(array_values(array_unique(array_merge([$domain], $pairedUrls))), $serviceName);

                return;
            }

            $fqdn = generateUrl(server: $server, random: $this->application->uuid);
            $merged = $this->currentDomainList()->push($fqdn)->unique()->values();
            if (! $this->saveDomainList($merged, null, checkConflicts: false)) {
                return;
            }

            $pairedUrls = $this->syncRedirectDomainPairs(null);
            $this->resetAddDomainForm();
            $this->dispatch('close-modal');
            $this->dispatch('success', 'Domain generated.');
            $this->refreshDomains();
            $this->checkUrlsDns(array_values(array_unique(array_merge([$fqdn], $pairedUrls))));
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
    }

    public function setRedirect(): void
    {
        try {
            $this->authorize('update', $this->application);

            if ($this->isCompose) {
                $this->dispatch('error', 'Set the redirect direction per compose service.');

                return;
            }

            $this->validateOnly('redirect');

            $this->application->redirect = $this->redirect;

            // www / non-www redirects need both hosts configured as real domains so the
            // proxy can serve the canonical host and redirect the other. Auto-add missing
            // counterparts instead of leaving them as optional suggestions.
            $addedDomains = [];
            if (in_array($this->redirect, ['www', 'non-www'], true)) {
                $domainsBeforePairing = $this->currentDomainList();
                if (! $this->ensureWwwNonWwwPairsConfigured(null)) {
                    return;
                }

                $this->application->refresh();
                $this->application->redirect = $this->redirect;
                $addedDomains = $this->currentDomainList()->diff($domainsBeforePairing)->values()->all();
            }

            $domains = collect($this->application->fqdns);
            if (! $this->assertRedirectDomainsPresent($this->redirect, $domains)) {
                return;
            }

            $this->application->save();
            $this->pendingAction = null;
            $this->pendingRedirectService = null;
            $this->forceSaveDomains = false;
            $this->resetDefaultLabels();
            $this->dispatch('success', 'Redirect updated.');
            $this->refreshDomains();
            $this->checkUrlsDns($addedDomains);
            $this->pruneDomainDnsStatusesToCurrentDomains();
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
    }

    public function updateServiceRedirect(string $serviceName, string $redirect): void
    {
        $this->serviceRedirects[$this->serviceRedirectWireKey($serviceName)] = $redirect;
        $this->setServiceRedirect($serviceName);
    }

    /**
     * @param  mixed  ...$modalArgs  Extra args from modal-confirmation (password, etc.)
     */
    public function setServiceRedirect(string $serviceName, mixed ...$modalArgs): void
    {
        try {
            $this->authorize('update', $this->application);

            if (! $this->isCompose) {
                $this->dispatch('error', 'Per-service redirect is only available for Docker Compose applications.');

                return;
            }

            // modal-confirmation passes string args with surrounding quotes intact.
            $serviceName = trim($serviceName, " \t\n\r\0\x0B'\"");

            if (blank($serviceName)) {
                $this->dispatch('error', 'A service is required.');

                return;
            }

            // Drop any nested arrays left from broken wire:model paths (e.g. service names with dots).
            $this->serviceRedirects = collect($this->serviceRedirects)
                ->filter(fn ($value) => is_string($value) || is_numeric($value))
                ->map(fn ($value) => $this->normalizeRedirect(is_string($value) ? $value : (string) $value))
                ->all();

            $wireKey = $this->serviceRedirectWireKey($serviceName);
            if (! array_key_exists($wireKey, $this->serviceRedirects)) {
                $this->serviceRedirects[$wireKey] = 'both';
            }

            $this->validateOnly("serviceRedirects.{$wireKey}");
            $redirect = $this->normalizeRedirect($this->serviceRedirects[$wireKey] ?? null);
            $this->serviceRedirects[$wireKey] = $redirect;
            $this->pendingRedirectService = $serviceName;

            // Promote the optional www/non-www suggestion to a real domain for redirects.
            $addedDomains = [];
            if (in_array($redirect, ['www', 'non-www'], true)) {
                $domainsBeforePairing = $this->currentDomainList($serviceName);
                if (! $this->ensureWwwNonWwwPairsConfigured($serviceName)) {
                    return;
                }
                // Ensure we re-read domains after pair save before writing redirect.
                $this->application->refresh();
                $addedDomains = $this->currentDomainList($serviceName)->diff($domainsBeforePairing)->values()->all();
            }

            $allDomains = $this->application->docker_compose_domains
                ? json_decode($this->application->docker_compose_domains, true)
                : [];
            if (! is_array($allDomains)) {
                $allDomains = [];
            }

            $existing = is_array($allDomains[$serviceName] ?? null) ? $allDomains[$serviceName] : [];
            $allDomains[$serviceName] = array_merge($existing, [
                'redirect' => $redirect,
            ]);

            // Keep domain key present when only redirect is set.
            if (! array_key_exists('domain', $allDomains[$serviceName])) {
                $allDomains[$serviceName]['domain'] = null;
            }

            $this->application->docker_compose_domains = json_encode($allDomains);
            $this->application->save();

            $domains = $this->currentDomainList($serviceName);
            if (! $this->assertRedirectDomainsPresent($redirect, $domains)) {
                return;
            }

            $this->pendingAction = null;
            $this->pendingRedirectService = null;
            $this->forceSaveDomains = false;
            $this->resetDefaultLabels();
            if ($this->notifyRedirectUpdate) {
                $this->dispatch('success', "Redirect updated for {$serviceName}.");
            }
            $this->refreshDomains();
            $this->checkUrlsDns($addedDomains, $serviceName);
            $this->pruneDomainDnsStatusesToCurrentDomains();
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
     * When saved redirect is www/non-www, ensure missing counterparts exist as real domains
     * (not suggestion rows the user must click Add domain for).
     *
     * @return array<int, string> newly added domain URLs
     */
    protected function syncRedirectDomainPairs(?string $serviceName = null): array
    {
        if ($this->labelsAreWritable) {
            return [];
        }

        $user = auth()->user();
        if ($user === null || ! $user->can('update', $this->application)) {
            return [];
        }

        if ($this->isCompose && $serviceName === null) {
            $added = [];
            $serviceNames = $this->composeServices;
            $domains = $this->application->docker_compose_domains
                ? json_decode($this->application->docker_compose_domains, true)
                : [];
            if (is_array($domains)) {
                foreach (array_keys($domains) as $name) {
                    if (! in_array($name, $serviceNames, true)) {
                        $serviceNames[] = $name;
                    }
                }
            }
            foreach ($serviceNames as $name) {
                $added = array_merge($added, $this->syncRedirectDomainPairs($name));
            }

            return array_values(array_unique($added));
        }

        $redirect = $this->savedRedirectForService($serviceName);
        if (! in_array($redirect, ['www', 'non-www'], true)) {
            return [];
        }

        $before = $this->currentDomainList($serviceName)->all();
        if (! $this->ensureWwwNonWwwPairsConfigured($serviceName)) {
            return [];
        }

        $this->application->refresh();
        $after = $this->currentDomainList($serviceName);

        return $after->reject(fn (string $url) => in_array($url, $before, true))->values()->all();
    }

    protected function savedRedirectForService(?string $serviceName): string
    {
        if ($this->isCompose && filled($serviceName)) {
            $domains = $this->application->docker_compose_domains
                ? json_decode($this->application->docker_compose_domains, true)
                : [];
            $entry = is_array($domains) ? ($domains[$serviceName] ?? null) : null;
            $stored = is_array($entry) ? ($entry['redirect'] ?? null) : null;

            return $this->normalizeRedirect(is_string($stored) ? $stored : null);
        }

        return $this->normalizeRedirect($this->application->redirect ?? null);
    }

    /**
     * Persist missing www/non-www counterparts as normal domains (not suggestions).
     *
     * @return bool false when save was blocked (e.g. domain conflict modal shown)
     */
    protected function ensureWwwNonWwwPairsConfigured(?string $serviceName = null): bool
    {
        $current = $this->currentDomainList($serviceName);
        $knownHosts = [];

        foreach ($current as $url) {
            $host = $this->domainHost($url);
            if ($host !== null) {
                $knownHosts[strtolower($host)] = true;
            }
        }

        $toAdd = collect();
        foreach ($current as $url) {
            // Include sslip/nip so compose/dev hosts can still get redirect pairs.
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

            $knownHosts[$hostKey] = true;
            $toAdd->push($counterpart);
        }

        if ($toAdd->isEmpty()) {
            return true;
        }

        $merged = $current->merge($toAdd)->unique()->values();
        $this->pendingAction = 'redirect';
        $this->pendingRedirectService = $serviceName;

        // Skip DNS: pairing for redirects must still be configured even when DNS is not ready.
        if (! $this->saveDomainList($merged, $serviceName)) {
            return false;
        }

        $this->pendingAction = null;
        $this->pendingRedirectService = null;

        return true;
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
     * Build the www/non-www counterpart URL for a host.
     *
     * @param  bool  $forRedirectPairing  When true, also pair sslip/nip hosts so www↔non-www
     *                                    redirects can be configured (suggestions still skip them).
     */
    protected function wwwCounterpartUrl(string $url, bool $forRedirectPairing = false): ?string
    {
        $host = $this->domainHost($url);
        if ($host === null) {
            return null;
        }

        $lowerHost = strtolower($host);

        // Always skip bare IPs and localhost.
        if (filter_var($host, FILTER_VALIDATE_IP) !== false || $lowerHost === 'localhost') {
            return null;
        }

        // Optional suggestions skip auto-generated hosts; redirect pairing includes them so
        // Set Direction can promote the missing side to a real domain.
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

    /**
     * @return Collection<int, string>
     */
    protected function currentDomainList(?string $serviceName = null): Collection
    {
        if ($this->isCompose) {
            $domains = $this->application->docker_compose_domains
                ? json_decode($this->application->docker_compose_domains, true)
                : [];

            if (! is_array($domains)) {
                $domains = [];
            }

            $domainString = is_array($domains[$serviceName] ?? null)
                ? ($domains[$serviceName]['domain'] ?? null)
                : null;

            return collect($this->splitDomains(is_string($domainString) ? $domainString : null));
        }

        return collect($this->splitDomains($this->application->fqdn));
    }

    /**
     * @param  Collection<int, string>  $domains
     */
    protected function saveDomainList(
        Collection $domains,
        ?string $serviceName = null,
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

        if ($this->isCompose) {
            if (blank($serviceName)) {
                $this->dispatch('error', 'A service is required for compose domains.');

                return false;
            }

            $allDomains = $this->application->docker_compose_domains
                ? json_decode($this->application->docker_compose_domains, true)
                : [];

            if (! is_array($allDomains)) {
                $allDomains = [];
            }

            $existing = is_array($allDomains[$serviceName] ?? null) ? $allDomains[$serviceName] : [];
            // Preserve stored redirect only — pending Direction dropdown values must not
            // persist until setServiceRedirect() runs.
            $allDomains[$serviceName] = array_merge($existing, [
                'domain' => $domainString,
            ]);

            $this->application->docker_compose_domains = json_encode($allDomains);
            $this->application->fqdn = null;
        } else {
            $this->application->fqdn = $domainString;
        }

        if ($checkConflicts && ! $this->forceSaveDomains) {
            $result = checkDomainUsage(resource: $this->application);
            if ($result['hasConflicts']) {
                $this->domainConflicts = $result['conflicts'];
                $this->showDomainConflictModal = true;

                // Revert in-memory mutation so a cancelled conflict leaves model clean
                $this->application->refresh();

                return false;
            }
        } else {
            $this->forceSaveDomains = false;
        }

        $warning = sslipDomainWarning($domainString ?? '');
        if ($warning) {
            $this->dispatch('warning', __('warning.sslipdomain'));
        }

        $this->application->save();
        $this->resetDefaultLabels();
        $this->dispatch('configurationChanged');

        return true;
    }

    protected function resetDefaultLabels(): void
    {
        try {
            if (! $this->application->settings->is_container_label_readonly_enabled) {
                return;
            }

            $customLabels = str(implode('|coolify|', generateLabelsApplication($this->application)))->replace('|coolify|', "\n");
            $this->application->custom_labels = base64_encode($customLabels);
            $this->application->save();
            $this->dispatch('configurationChanged');
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
    }

    public function render()
    {
        return view('livewire.project.application.domains');
    }
}
