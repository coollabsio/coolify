<?php

namespace App\Livewire\Project\Application;

use App\Models\Application;
use App\Models\Server;
use App\Support\ValidationPatterns;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Collection;
use Livewire\Component;

class Domains extends Component
{
    use AuthorizesRequests;

    public Application $application;

    public string $redirect = 'both';

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

    public ?string $newDomainService = null;

    public ?int $editingIndex = null;

    public string $editingDomain = '';

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

    public function loadDomainState(): void
    {
        $this->application->refresh();
        $this->application->loadMissing(['destination.server', 'settings', 'additional_servers']);

        $this->isCompose = $this->application->build_pack === 'dockercompose';
        $this->labelsAreWritable = $this->application->settings->is_container_label_readonly_enabled === false;
        $this->redirect = $this->application->redirect ?? 'both';

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

                foreach ($this->buildSuggestedWwwRows($configured, $stored, $serviceName) as $suggested) {
                    $rows[] = $suggested;
                }
            }

            return $rows;
        }

        foreach ($this->splitDomains($this->application->fqdn) as $url) {
            $rows[] = $this->domainRowFromStored($url, null, $stored);
        }

        return array_merge($rows, $this->buildSuggestedWwwRows($rows, $stored));
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
            $base['suggestion_label'] = $meta['label'];
            $base['suggestion_role'] = $meta['role'];
            $base['needs_force_add'] = false;

            // Always show role-specific guidance for suggested rows (even after DNS checks).
            if (($base['dns_status'] ?? 'pending') === 'pending') {
                $base['dns_message'] = $meta['pending_message'];
            } elseif (in_array($base['dns_status'], ['ok', 'failed', 'skipped'], true)) {
                // Keep stored DNS result message, but append role context when redirect is set.
                if ($meta['role'] !== 'pair' && ! str_contains((string) $base['dns_message'], 'redirect')) {
                    $base['dns_message'] = trim((string) $base['dns_message'].' '.$meta['dns_suffix']);
                }
            }

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
        $pointDns = dnsMismatchGuidanceMessage($this->dnsTargetLabel(), $this->serverIp);

        $redirect = $redirectOverride ?? ($this->redirect ?: 'both');

        return match ($redirect) {
            'www' => $suggestedIsWww
                ? [
                    'label' => 'Canonical www',
                    'role' => 'canonical',
                    'pending_message' => "Required as the redirect target (www). {$pointDns}",
                    'dns_suffix' => 'This is the canonical www host traffic should land on.',
                ]
                : [
                    'label' => 'Redirect source',
                    'role' => 'redirect_source',
                    'pending_message' => "Needed so Coolify can redirect non-www to www. {$pointDns}",
                    'dns_suffix' => 'Used only so Coolify can redirect this host to www. Still needs DNS to the server, not a provider URL-redirect record.',
                ],
            'non-www' => $suggestedIsWww
                ? [
                    'label' => 'Redirect source',
                    'role' => 'redirect_source',
                    'pending_message' => "Needed so Coolify can redirect www to non-www. {$pointDns}",
                    'dns_suffix' => 'Used only so Coolify can redirect this host to non-www. Still needs DNS to the server, not a provider URL-redirect record.',
                ]
                : [
                    'label' => 'Canonical non-www',
                    'role' => 'canonical',
                    'pending_message' => "Required as the redirect target (non-www). {$pointDns}",
                    'dns_suffix' => 'This is the canonical non-www host traffic should land on.',
                ],
            default => [
                'label' => $suggestedIsWww ? 'Suggested www' : 'Suggested non-www',
                'role' => 'pair',
                'pending_message' => "Also add this host so both www and non-www work. {$pointDns}",
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

    public function checkAllDns(): void
    {
        $this->authorize('update', $this->application);

        $this->isCheckingDns = true;

        try {
            $server = $this->application->destination?->server;
            $skipDns = ! $this->dnsValidationEnabled
                || ! $server
                || $this->application->additional_servers->count() > 0;

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

                $this->applyDnsStatus($index, $row['url'], $server);
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

        $this->applyDnsStatus($index, $this->domainRows[$index]['url'], $server);
        $this->persistDomainDnsStatuses();
    }

    protected function applyDnsStatus(int $index, string $url, Server $server): void
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

        // Clarify purpose for redirect-source / canonical suggested hosts.
        if ($this->domainRows[$index]['is_suggested'] ?? false) {
            $isWww = str_starts_with(strtolower((string) $this->domainHost((string) $this->domainRows[$index]['url'])), 'www.');
            $serviceName = $this->domainRows[$index]['service'] ?? null;
            $meta = $this->suggestedDomainMeta(
                $isWww,
                $this->serviceRedirectFor(is_string($serviceName) ? $serviceName : null)
            );
            if ($meta['dns_suffix'] !== '') {
                $this->domainRows[$index]['dns_message'] = trim($this->domainRows[$index]['dns_message'].' '.$meta['dns_suffix']);
            }
            $this->domainRows[$index]['suggestion_label'] = $meta['label'];
            $this->domainRows[$index]['suggestion_role'] = $meta['role'];
        }

        $this->domainRows[$index]['expected_ip'] = $this->serverIp;
        $this->domainRows[$index]['checked_at'] = now()->toIso8601String();
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
            ];
        }

        $this->application->domain_dns_statuses = $statuses === [] ? null : $statuses;
        $this->application->save();
    }

    /**
     * Store a DNS result for a domain that was just added/updated (may not be in domainRows yet).
     *
     * @param  array<int, string>  $urls
     */
    protected function rememberDomainDnsResults(array $urls, string $status, string $message, ?string $service = null): void
    {
        $statuses = $this->storedDomainDnsStatuses();
        $checkedAt = now()->toIso8601String();

        foreach ($urls as $url) {
            $statuses[$this->domainDnsStatusKey($url, $service)] = [
                'status' => $status,
                'message' => $message,
                'expected_ip' => $this->serverIp,
                'checked_at' => $checkedAt,
            ];
        }

        // Drop entries for domains that no longer exist after the upcoming refresh.
        $this->application->domain_dns_statuses = $statuses;
        $this->application->save();
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

            $this->validateOnly('newDomain');

            $normalized = ValidationPatterns::normalizeApplicationDomains($this->newDomain);
            if (blank($normalized)) {
                $this->addError('newDomain', 'Please enter a valid domain URL.');

                return;
            }

            $newUrls = $this->splitDomains($normalized);
            $current = $this->currentDomainList($this->newDomainService);

            foreach ($newUrls as $url) {
                if ($current->contains($url)) {
                    $this->addError('newDomain', "Domain {$url} is already configured.");

                    return;
                }
            }

            if (! $this->forceSaveDns && $this->shouldValidateDnsForAdd()) {
                $dnsFailure = $this->findDnsFailureMessage($newUrls);
                if ($dnsFailure !== null) {
                    $this->addDomainDnsFailed = true;
                    $this->addDomainDnsMessage = $dnsFailure;

                    return;
                }
            }

            $merged = $current->merge($newUrls)->unique()->values();
            $this->pendingAction = 'add';
            // DNS was already validated (or overridden) in the modal; skip save-time toast noise.
            if (! $this->saveDomainList($merged, $this->newDomainService, checkDns: false)) {
                return;
            }

            $this->storeDnsResultForUrls(
                $newUrls,
                $this->newDomainService,
                forcedFailure: $this->forceSaveDns,
                failureMessage: $this->addDomainDnsMessage,
            );

            $this->forceSaveDomains = false;
            $this->pendingAction = null;
            $this->resetAddDomainForm();
            $this->dispatch('close-modal');
            $this->dispatch('success', 'Domain added.');
            $this->refreshDomains();
            $this->pruneDomainDnsStatusesToCurrentDomains();
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
    }

    /**
     * @param  array<int, string>  $urls
     */
    protected function storeDnsResultForUrls(
        array $urls,
        ?string $service,
        bool $forcedFailure = false,
        string $failureMessage = '',
    ): void {
        if (! $this->shouldValidateDnsForAdd() && ! $forcedFailure) {
            return;
        }

        $target = $this->dnsTargetLabel();

        if ($forcedFailure) {
            $message = $failureMessage !== ''
                ? $failureMessage
                : dnsMismatchGuidanceMessage($target, $this->serverIp);
            $this->rememberDomainDnsResults($urls, 'failed', $message, $service);

            return;
        }

        $message = $target
            ? "DNS points to {$target} (or Cloudflare)."
            : 'DNS looks correct.';
        $this->rememberDomainDnsResults($urls, 'ok', $message, $service);
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

    public function updatedEditingDomain(): void
    {
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
        $this->editingService = $this->domainRows[$index]['service'];
        $this->resetEditDomainDnsGate();
        $this->resetErrorBag('editingDomain');
        $this->showEditDomainModal = true;
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
                    $this->dispatch('error', 'DNS validation failed.', $dnsFailure);

                    return;
                }
            }

            $merged = $current->merge($newUrls)->unique()->values();
            $this->pendingAction = 'suggested';
            $this->editingIndex = $index;
            if (! $this->saveDomainList($merged, $serviceName, checkDns: false)) {
                return;
            }

            $this->storeDnsResultForUrls(
                $newUrls,
                $serviceName,
                forcedFailure: $force && $this->shouldValidateDnsForAdd(),
                failureMessage: (string) ($this->domainRows[$index]['dns_message'] ?? ''),
            );

            $this->forceAddSuggestedIndex = null;
            $this->editingIndex = null;
            $this->pendingAction = null;
            $this->forceSaveDomains = false;
            $this->dispatch('success', 'Domain added.');
            $this->refreshDomains();
            $this->pruneDomainDnsStatusesToCurrentDomains();
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
    }

    public function cancelEdit(): void
    {
        $this->showEditDomainModal = false;
        $this->editingIndex = null;
        $this->editingDomain = '';
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

            $this->validateOnly('editingDomain');

            $normalized = ValidationPatterns::normalizeApplicationDomains($this->editingDomain);
            if (blank($normalized) || count($this->splitDomains($normalized)) !== 1) {
                $this->addError('editingDomain', 'Please enter a single valid domain URL.');

                return;
            }

            $newUrl = $this->splitDomains($normalized)[0];
            $oldUrl = $this->domainRows[$this->editingIndex]['url'];
            $service = $this->editingService;

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

                    return;
                }
            }

            $updated = $current->map(fn (string $url) => $url === $oldUrl ? $newUrl : $url)->unique()->values();
            $this->pendingAction = 'update';
            if (! $this->saveDomainList($updated, $service, checkDns: false)) {
                return;
            }

            $this->storeDnsResultForUrls(
                [$newUrl],
                $service,
                forcedFailure: $this->forceSaveEditDns,
                failureMessage: $this->editDomainDnsMessage,
            );

            $this->forceSaveDomains = false;
            $this->pendingAction = null;
            $this->cancelEdit();
            $this->dispatch('success', 'Domain updated.');
            $this->refreshDomains();
            $this->pruneDomainDnsStatusesToCurrentDomains();
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

            if (! $this->saveDomainList($updated, $service, checkConflicts: false, checkDns: false)) {
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

                if (! $this->saveDomainList($merged, $serviceName, checkConflicts: false, checkDns: false)) {
                    return;
                }

                $this->resetAddDomainForm();
                $this->dispatch('close-modal');
                $this->dispatch('success', 'Domain generated.');
                $this->refreshDomains();

                return;
            }

            $fqdn = generateUrl(server: $server, random: $this->application->uuid);
            $this->application->fqdn = $fqdn;
            $this->application->save();
            $this->resetDefaultLabels();
            $this->resetAddDomainForm();
            $this->dispatch('close-modal');
            $this->dispatch('success', 'Domain generated.');
            $this->refreshDomains();
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
            if (in_array($this->redirect, ['www', 'non-www'], true)) {
                if (! $this->ensureWwwNonWwwPairsConfigured(null)) {
                    return;
                }

                $this->application->refresh();
                $this->application->redirect = $this->redirect;
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
            $this->pruneDomainDnsStatusesToCurrentDomains();
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
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
            if (in_array($redirect, ['www', 'non-www'], true)) {
                if (! $this->ensureWwwNonWwwPairsConfigured($serviceName)) {
                    return;
                }
                // Ensure we re-read domains after pair save before writing redirect.
                $this->application->refresh();
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
            $this->dispatch('success', "Redirect updated for {$serviceName}.");
            $this->refreshDomains();
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
        if (! $this->saveDomainList($merged, $serviceName, checkDns: false)) {
            return false;
        }

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
        bool $checkDns = true,
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

        if ($checkDns && $domainString && $this->application->additional_servers->count() === 0) {
            $server = $this->application->destination?->server;
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
                            "{$guidance}<br><br>Check this <a target='_blank' class='underline dark:text-white' href='https://coolify.io/docs/knowledge-base/dns-configuration'>documentation</a> for further help."
                        );
                    }
                }
            }
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
