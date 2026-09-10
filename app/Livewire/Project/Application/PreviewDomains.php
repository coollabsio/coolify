<?php

namespace App\Livewire\Project\Application;

use App\Actions\Shared\CheckDomainDns;
use App\Jobs\CheckDomainDnsJob;
use App\Models\ApplicationPreview;
use App\Support\DomainPortOverrides;
use App\Support\DomainUrlParts;
use App\Support\ValidationPatterns;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\DB;
use Livewire\Component;

class PreviewDomains extends Component
{
    use AuthorizesRequests;

    public ApplicationPreview $preview;

    public array $domainRows = [];

    public array $newDomainParts = ['scheme' => 'https', 'host' => '', 'port' => '', 'path' => ''];

    public ?string $newDomainService = null;

    public array $editingDomainParts = ['scheme' => 'https', 'host' => '', 'port' => '', 'path' => ''];

    public ?int $editingIndex = null;

    public bool $showPortWarningModal = false;

    public bool $forceUseUnknownPort = false;

    public ?int $unrecognizedPort = null;

    public ?string $pendingPortAction = null;

    public function mount(): void
    {
        $this->authorize('view', $this->preview->application);
        $this->refreshDomains();
        if ($this->preview->application->build_pack === 'dockercompose') {
            $this->newDomainService = $this->composeServices()[0] ?? null;
        }
    }

    public function render()
    {
        return view('livewire.project.application.preview-domains', [
            'isCompose' => $this->preview->application->build_pack === 'dockercompose',
            'composeServices' => $this->composeServices(),
        ]);
    }

    public function addDomain(): void
    {
        $this->authorize('update', $this->preview->application);
        if ($this->preview->application->build_pack === 'dockercompose'
            && ($this->newDomainService === null || ! in_array($this->newDomainService, $this->composeServices(), true))) {
            $this->addError('newDomainService', 'Select a valid Compose service.');

            return;
        }
        $domain = $this->validatedDomain($this->newDomainParts, 'newDomainParts.host');
        if ($domain === null) {
            return;
        }
        $canonicalDomain = DomainPortOverrides::withoutPort($domain);
        if (collect($this->domainRows)->contains(
            fn (array $row): bool => DomainPortOverrides::withoutPort($row['url']) === $canonicalDomain
                && $row['service'] === $this->newDomainService
        )) {
            $this->addError('newDomainParts.host', 'This domain is already configured.');

            return;
        }
        if ($this->shouldConfirmPort($this->portFromParts($this->newDomainParts), serviceName: $this->newDomainService)) {
            $this->openPortWarning($this->portFromParts($this->newDomainParts), 'add');

            return;
        }
        $this->domainRows[] = $this->makeRow($domain, $this->newDomainService);
        $index = array_key_last($this->domainRows);
        $checkId = new_public_id();
        $this->domainRows[$index]['dns_status'] = 'checking';
        $this->domainRows[$index]['dns_message'] = 'Checking DNS...';
        $this->domainRows[$index]['check_id'] = $checkId;
        if (! $this->persistDomains()) {
            return;
        }
        $domain = $this->domainRows[$index]['url'] ?? DomainPortOverrides::withoutPort($domain);
        $this->newDomainParts = DomainUrlParts::empty();
        $this->newDomainService = $this->preview->application->build_pack === 'dockercompose'
            ? ($this->composeServices()[0] ?? null)
            : null;
        $this->forceUseUnknownPort = false;
        $this->dispatch('close-preview-domain-add', previewId: $this->preview->id);

        try {
            $server = $this->preview->application->destination?->server;
            CheckDomainDnsJob::dispatch(
                $this->preview,
                $this->statusKey($domain, $this->domainRows[$index]['service']),
                $domain,
                $server,
                $server ? serverDnsTargetIp($server) ?? $server->ip : null,
                $checkId,
                $this->preview->application->additional_servers->count() > 0,
            );
            $this->dispatch('success', 'Domain added. DNS check started.');
        } catch (\Throwable) {
            $this->domainRows[$index]['dns_status'] = 'skipped';
            $this->domainRows[$index]['dns_message'] = 'DNS check could not be started.';
            $this->domainRows[$index]['check_id'] = null;
            $this->persistDnsStatuses();
            $this->dispatch('error', 'Domain added, but the DNS check could not be started. Try again from the preview domains list.');
        }
    }

    public function generateDomain(): void
    {
        $this->authorize('update', $this->preview->application);
        $this->preview->refresh();
        if ($this->preview->application->build_pack === 'dockercompose') {
            if ($this->newDomainService === null && $this->domainRows === []) {
                $this->preview->generate_preview_fqdn_compose(generateWithoutApplicationDomain: true);
            } else {
                $service = $this->newDomainService ?? data_get($this->domainRows, '0.service');
                foreach ($this->generateComposeDomains((string) $service) as $domain) {
                    $alreadyExists = collect($this->domainRows)->contains(
                        fn (array $row): bool => DomainPortOverrides::withoutPort($row['url']) === DomainPortOverrides::withoutPort($domain)
                            && $row['service'] === $service
                    );
                    if (! $alreadyExists) {
                        $this->domainRows[] = $this->makeRow($domain, $service);
                    }
                }

                if (! $this->persistDomains()) {
                    return;
                }
            }
        } else {
            $this->preview->generate_preview_fqdn(generateWithoutApplicationDomain: true);
        }
        $this->refreshDomains();
        $this->dispatch('success', 'Domain generated.');
    }

    public function startEdit(int $index): void
    {
        $this->authorize('update', $this->preview->application);
        if (! isset($this->domainRows[$index])) {
            return;
        }
        $this->editingIndex = $index;
        $this->editingDomainParts = DomainUrlParts::split($this->domainRows[$index]['url']);
        $canonical = DomainPortOverrides::withoutPort($this->domainRows[$index]['url']);
        $savedPort = ($this->preview->domain_port_overrides ?? [])[$canonical] ?? null;
        if (filled($savedPort)) {
            $this->editingDomainParts['port'] = (string) $savedPort;
        }
        $this->resetErrorBag('editingDomainParts.host');
        $this->dispatch('open-preview-domain-edit', previewId: $this->preview->id);
    }

    public function updateDomain(): void
    {
        $this->authorize('update', $this->preview->application);
        if ($this->editingIndex === null || ! isset($this->domainRows[$this->editingIndex])) {
            return;
        }
        $domain = $this->validatedDomain($this->editingDomainParts, 'editingDomainParts.host');
        if ($domain === null) {
            return;
        }
        $oldUrl = $this->domainRows[$this->editingIndex]['url'];
        $dnsRelevantChange = DomainUrlParts::hasDnsRelevantChange($oldUrl, $domain);
        if ($this->shouldConfirmPort($this->portFromParts($this->editingDomainParts), $this->currentRowPort($oldUrl), $this->domainRows[$this->editingIndex]['service'])) {
            $this->openPortWarning($this->portFromParts($this->editingDomainParts), 'update');

            return;
        }
        if (blank(DomainUrlParts::split($domain)['port'] ?? null)) {
            $portOverrides = $this->preview->domain_port_overrides ?? [];
            unset($portOverrides[DomainPortOverrides::withoutPort($oldUrl)]);
            unset($portOverrides[DomainPortOverrides::withoutPort($domain)]);
            $this->preview->domain_port_overrides = $portOverrides ?: null;
        }
        $this->domainRows[$this->editingIndex]['url'] = $domain;
        $checkId = $dnsRelevantChange ? new_public_id() : null;
        if ($dnsRelevantChange) {
            $this->domainRows[$this->editingIndex]['dns_status'] = 'checking';
            $this->domainRows[$this->editingIndex]['dns_message'] = 'Checking DNS...';
            $this->domainRows[$this->editingIndex]['check_id'] = $checkId;
        }
        $index = $this->editingIndex;
        $this->editingIndex = null;
        if (! $this->persistDomains()) {
            return;
        }
        $domain = $this->domainRows[$index]['url'];
        $this->forceUseUnknownPort = false;
        $this->dispatch('close-preview-domain-edit', previewId: $this->preview->id);

        if (! $dnsRelevantChange) {
            $this->dispatch('success', 'Domain updated.');

            return;
        }

        try {
            $server = $this->preview->application->destination?->server;
            CheckDomainDnsJob::dispatch(
                $this->preview,
                $this->statusKey($domain, $this->domainRows[$index]['service']),
                $domain,
                $server,
                $server ? serverDnsTargetIp($server) ?? $server->ip : null,
                $checkId,
                $this->preview->application->additional_servers->count() > 0,
            );
            $this->dispatch('success', 'Domain updated. DNS check started.');
        } catch (\Throwable) {
            $this->domainRows[$index]['dns_status'] = 'skipped';
            $this->domainRows[$index]['dns_message'] = 'DNS check could not be started.';
            $this->domainRows[$index]['check_id'] = null;
            $this->persistDnsStatuses();
            $this->dispatch('error', 'Domain updated, but the DNS check could not be started. Try again from the preview domains list.');
        }
    }

    public function regenerateEditingDomain(): void
    {
        $this->authorize('update', $this->preview->application);
        if ($this->editingIndex === null || ! isset($this->domainRows[$this->editingIndex])) {
            return;
        }

        $server = $this->preview->application->destination?->server;
        if (! $server) {
            $this->dispatch('error', 'No server found for this preview.');

            return;
        }

        $host = parse_url(generateUrl(server: $server, random: new_public_id()), PHP_URL_HOST);
        if (! is_string($host) || $host === '') {
            return;
        }

        $this->editingDomainParts['host'] = str_starts_with(strtolower((string) $this->editingDomainParts['host']), 'www.') ? 'www.'.$host : $host;
    }

    public function cancelEdit(): void
    {
        $this->editingIndex = null;
        $this->editingDomainParts = DomainUrlParts::empty();
        $this->resetErrorBag('editingDomainParts.host');
    }

    public function confirmUseUnknownPort(): void
    {
        $this->authorize('update', $this->preview->application);
        $this->forceUseUnknownPort = true;
        $this->showPortWarningModal = false;
        $action = $this->pendingPortAction;
        $this->pendingPortAction = null;

        if ($action === 'update') {
            $this->updateDomain();

            return;
        }

        $this->addDomain();
    }

    public function cancelUseUnknownPort(): void
    {
        $this->showPortWarningModal = false;
        $this->forceUseUnknownPort = false;
        $this->unrecognizedPort = null;
        $this->pendingPortAction = null;
    }

    public function removeDomain(int $index): void
    {
        $this->authorize('update', $this->preview->application);
        if (! isset($this->domainRows[$index])) {
            return;
        }
        if ($this->editingIndex === $index) {
            $this->editingIndex = null;
            $this->dispatch('close-preview-domain-edit', previewId: $this->preview->id);
        } elseif ($this->editingIndex !== null && $this->editingIndex > $index) {
            $this->editingIndex--;
        }
        unset($this->domainRows[$index]);
        $this->domainRows = array_values($this->domainRows);
        if (! $this->persistDomains()) {
            return;
        }
        $this->dispatch('success', 'Domain removed.');
    }

    public function removeDomainByKey(string $domainKey): void
    {
        $index = collect($this->domainRows)->search(
            fn (array $row): bool => hash_equals($domainKey, $this->statusKey($row['url'], $row['service']))
        );

        if ($index === false) {
            return;
        }

        $this->removeDomain((int) $index);
    }

    public function checkAllDns(): void
    {
        $this->authorize('update', $this->preview->application);
        foreach (array_keys($this->domainRows) as $index) {
            $this->queueDnsCheck($index);
        }
    }

    public function checkDomainDns(int $index): void
    {
        $this->authorize('update', $this->preview->application);
        $this->queueDnsCheck($index);
    }

    private function queueDnsCheck(int $index): void
    {
        if (! isset($this->domainRows[$index])) {
            return;
        }

        $row = $this->domainRows[$index];
        $checkId = new_public_id();
        $this->domainRows[$index]['dns_status'] = 'checking';
        $this->domainRows[$index]['dns_message'] = 'Checking DNS...';
        $this->domainRows[$index]['check_id'] = $checkId;
        $this->persistDnsStatuses();

        try {
            $server = $this->preview->application->destination?->server;
            CheckDomainDnsJob::dispatch(
                $this->preview,
                $this->statusKey($row['url'], $row['service']),
                $row['url'],
                $server,
                $server ? serverDnsTargetIp($server) ?? $server->ip : null,
                $checkId,
                $this->preview->application->additional_servers->count() > 0,
            );
        } catch (\Throwable) {
            $this->domainRows[$index]['dns_status'] = 'skipped';
            $this->domainRows[$index]['dns_message'] = 'DNS check could not be started.';
            $this->domainRows[$index]['check_id'] = null;
            $this->persistDnsStatuses();
        }
    }

    public function pollDnsChecks(): void
    {
        $this->authorize('view', $this->preview->application);
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

    private function dispatchDnsCheckNotification(string $url, string $status): void
    {
        $host = parse_url($url, PHP_URL_HOST) ?: $url;

        match ($status) {
            'ok' => $this->dispatch('success', "DNS is configured correctly for {$host}."),
            'failed' => $this->dispatch('error', "DNS is not configured for {$host}. Review the required DNS record."),
            default => $this->dispatch('info', "DNS check skipped for {$host}."),
        };
    }

    private function applyDnsCheck(int $index): void
    {
        if (! isset($this->domainRows[$index])) {
            return;
        }
        $result = $this->checkUrlDns($this->domainRows[$index]['url'], (string) $index);
        $this->domainRows[$index]['dns_status'] = $result['status'];
        $this->domainRows[$index]['dns_message'] = $result['message'];
    }

    private function checkUrlDns(string $url, string $key = 'domain'): array
    {
        $server = $this->preview->application->destination?->server;

        return CheckDomainDns::run(
            [$key => $url],
            $server,
            $server ? serverDnsTargetIp($server) ?? $server->ip : null,
            $this->preview->application->additional_servers->count() > 0,
        )[$key];
    }

    private function refreshDomains(): void
    {
        $editingRow = $this->editingIndex !== null ? ($this->domainRows[$this->editingIndex] ?? null) : null;
        $this->preview->refresh();
        $statuses = $this->preview->domain_dns_statuses ?? [];
        $rows = [];
        if ($this->preview->application->build_pack === 'dockercompose') {
            foreach (json_decode($this->preview->docker_compose_domains ?: '[]', true) ?: [] as $service => $entry) {
                foreach ($this->splitDomains(composeDomainEntryString($entry)) as $url) {
                    $rows[] = $this->makeRow($url, (string) $service, $statuses);
                }
            }
        } else {
            foreach ($this->splitDomains($this->preview->fqdn) as $url) {
                $rows[] = $this->makeRow($url, null, $statuses);
            }
        }
        $this->domainRows = $rows;
        if ($editingRow !== null) {
            $index = collect($this->domainRows)->search(fn (array $row): bool => $row['url'] === $editingRow['url']
                && $row['service'] === $editingRow['service']);
            $this->editingIndex = $index === false ? null : (int) $index;
        }
    }

    private function persistDomains(): bool
    {
        if ($this->preview->application->build_pack === 'dockercompose') {
            try {
                $composeServices = $this->composeServices(failOnError: true);
            } catch (\Throwable) {
                $this->refreshDomains();
                $this->dispatch('error', 'Compose configuration could not be parsed. Preview domains were not changed.');

                return false;
            }
            $existingDomains = json_decode($this->preview->docker_compose_domains ?: '[]', true) ?: [];
            $domains = [];
            foreach ($composeServices as $service) {
                $domains[$service] = is_array($existingDomains[$service] ?? null) ? $existingDomains[$service] : [];
                $domains[$service]['domain'] = '';
            }
            $validRows = collect($this->domainRows)
                ->filter(fn (array $row): bool => in_array($row['service'] ?? null, $composeServices, true));
            foreach ($validRows->groupBy('service') as $service => $rows) {
                $domains[$service]['domain'] = $rows->pluck('url')->implode(',');
            }
            $this->preview->docker_compose_domains = json_encode($domains);
            $this->preview->fqdn = $validRows->pluck('url')->implode(',') ?: null;
        } else {
            $this->preview->fqdn = collect($this->domainRows)->pluck('url')->implode(',') ?: null;
        }
        $normalized = DomainPortOverrides::normalize($this->preview->fqdn, $this->preview->domain_port_overrides);
        $this->preview->fqdn = $normalized['fqdn'];
        $this->preview->domain_port_overrides = $normalized['overrides'];
        if ($this->preview->application->build_pack === 'dockercompose' && is_array($domains ?? null)) {
            foreach ($domains as $service => $entry) {
                $serviceDomains = $this->splitDomains(composeDomainEntryString($entry));
                $domains[$service]['domain'] = collect($serviceDomains)
                    ->map(fn (string $url): string => DomainPortOverrides::withoutPort($url))
                    ->implode(',');
            }
            $this->preview->docker_compose_domains = json_encode($domains);
        }
        foreach ($this->domainRows as $index => $row) {
            $this->domainRows[$index]['url'] = DomainPortOverrides::withoutPort($row['url']);
        }
        $this->preview->save();
        $this->persistDnsStatuses();
        $this->refreshDomains();
        $this->dispatch('update_links');
        $this->dispatch('previewDomainsChanged');

        return true;
    }

    private function persistDnsStatuses(): void
    {
        $statuses = [];
        foreach ($this->domainRows as $row) {
            $statuses[$this->statusKey($row['url'], $row['service'])] = [
                'status' => $row['dns_status'],
                'message' => $row['dns_message'],
                'check_id' => $row['check_id'] ?? null,
            ];
        }

        DB::transaction(function () use (&$statuses): void {
            $preview = ApplicationPreview::query()->lockForUpdate()->findOrFail($this->preview->id);
            $storedStatuses = $preview->domain_dns_statuses ?? [];

            foreach ($statuses as $key => $status) {
                $storedStatus = $storedStatuses[$key] ?? null;
                if (! is_array($storedStatus)) {
                    continue;
                }

                $localCheckId = $status['check_id'] ?? null;
                $storedCheckId = $storedStatus['check_id'] ?? null;

                if (($storedCheckId !== null && $localCheckId !== $storedCheckId)
                    || ($status['status'] === 'checking' && ($storedStatus['status'] ?? null) !== 'checking')) {
                    $statuses[$key] = $storedStatus;
                }
            }

            $preview->domain_dns_statuses = $statuses ?: null;
            $preview->save();
        });

        $this->preview->domain_dns_statuses = $statuses ?: null;
    }

    private function validatedDomain(array $parts, string $errorKey): ?string
    {
        $domain = DomainUrlParts::compose(...$parts);
        $validator = validator(['domain' => $domain], ['domain' => ValidationPatterns::applicationDomainRules()]);
        if ($validator->fails()) {
            $this->addError($errorKey, $validator->errors()->first('domain'));

            return null;
        }

        return ValidationPatterns::normalizeApplicationDomains($domain);
    }

    private function makeRow(string $url, ?string $service, array $statuses = []): array
    {
        $status = $statuses[$this->statusKey($url, $service)] ?? [];
        $port = $this->effectiveDomainInternalPort($url, $service);
        $redirect = 'both';
        if ($this->preview->application->build_pack === 'dockercompose' && $service !== null) {
            $usesPreviewRedirect = (int) $this->preview->application->compose_parsing_version >= 3;
            $domains = json_decode(($usesPreviewRedirect
                ? $this->preview->docker_compose_domains
                : $this->preview->application->docker_compose_domains) ?: '[]', true) ?: [];
            $storedRedirect = $usesPreviewRedirect
                ? ($domains[$service]['redirect'] ?? null)
                : data_get($domains, "$service.redirect");
            $redirect = in_array($storedRedirect, ['www', 'non-www', 'both'], true) ? $storedRedirect : 'both';
        }

        return [
            'url' => $url,
            'service' => $service,
            'redirect' => $redirect,
            'internal_port' => $port['internal_port'],
            'has_port_override' => $port['has_port_override'],
            'dns_status' => $status['status'] ?? 'pending',
            'dns_message' => $status['message'] ?? 'DNS has not been checked yet.',
            'check_id' => $status['check_id'] ?? null,
        ];
    }

    /**
     * @param  array{scheme: string, host: string, port: string, path: string}  $parts
     */
    private function portFromParts(array $parts): ?int
    {
        $port = trim((string) ($parts['port'] ?? ''));
        if ($port === '' || ! ctype_digit($port) || (int) $port <= 0) {
            return null;
        }

        return (int) $port;
    }

    private function currentRowPort(string $url): ?int
    {
        $canonical = DomainPortOverrides::withoutPort($url);
        $override = ($this->preview->domain_port_overrides ?? [])[$canonical] ?? null;
        if (filled($override) && (int) $override > 0) {
            return (int) $override;
        }

        $legacy = DomainUrlParts::split($url)['port'] ?? '';

        return $legacy !== '' && ctype_digit($legacy) ? (int) $legacy : null;
    }

    private function shouldConfirmPort(?int $port, ?int $currentPort = null, ?string $serviceName = null): bool
    {
        if ($this->forceUseUnknownPort || $port === null) {
            return false;
        }
        if ($currentPort !== null && $port === $currentPort) {
            return false;
        }

        return $this->preview->application->portRequiresConfirmation($port, $serviceName);
    }

    private function openPortWarning(?int $port, string $action): void
    {
        $this->unrecognizedPort = $port;
        $this->pendingPortAction = $action;
        $this->showPortWarningModal = true;
    }

    /**
     * @return array{internal_port: ?int, has_port_override: bool}
     */
    private function effectiveDomainInternalPort(string $url, ?string $service = null): array
    {
        $canonical = DomainPortOverrides::withoutPort($url);
        $overrides = $this->preview->domain_port_overrides ?? [];
        $legacyPortPart = DomainUrlParts::split($url)['port'] ?? '';
        $legacyPort = $legacyPortPart !== '' ? (int) $legacyPortPart : null;
        $hasMapEntry = array_key_exists($canonical, $overrides);

        if ($hasMapEntry) {
            return [
                'internal_port' => (int) $overrides[$canonical],
                'has_port_override' => true,
            ];
        }

        if ($legacyPort !== null) {
            return [
                'internal_port' => $legacyPort,
                'has_port_override' => true,
            ];
        }

        $composePort = dockerComposeServicePort($this->preview->application->docker_compose_raw, $service);
        if ($composePort !== null) {
            return [
                'internal_port' => $composePort,
                'has_port_override' => false,
            ];
        }

        if ($this->preview->application->build_pack === 'dockercompose' && $service !== null) {
            return [
                'internal_port' => null,
                'has_port_override' => false,
            ];
        }

        if ($this->preview->application->settings?->is_static) {
            return [
                'internal_port' => 80,
                'has_port_override' => false,
            ];
        }

        $exposed = $this->preview->application->ports_exposes_array;
        $defaultPort = isset($exposed[0]) && is_numeric($exposed[0]) && (int) $exposed[0] > 0
            ? (int) $exposed[0]
            : null;

        return [
            'internal_port' => $defaultPort,
            'has_port_override' => false,
        ];
    }

    private function statusKey(string $url, ?string $service): string
    {
        return hash('sha256', $url.'|'.($service ?? ''));
    }

    private function splitDomains(?string $domains): array
    {
        return str($domains)->explode(',')->map(fn ($domain) => trim((string) $domain))->filter()->values()->all();
    }

    private function generateComposeDomains(string $service): array
    {
        $applicationDomains = json_decode($this->preview->application->docker_compose_domains ?: '[]', true) ?: [];
        $domainString = getComposeServiceDomainString($applicationDomains, $service);

        if (empty($domainString)) {
            $domainString = generateUrl(
                server: $this->preview->application->destination->server,
                random: str($service)->slug().'-'.$this->preview->application->uuid,
            );
        }

        return collect($this->splitDomains($domainString))->map(function (string $domain): string {
            $generated = $this->preview->generatedPreviewDomain($domain);
            if (filled($generated['port'])) {
                $overrides = $this->preview->domain_port_overrides ?? [];
                $overrides[$generated['url']] = $generated['port'];
                $this->preview->domain_port_overrides = $overrides;
            }

            return $generated['url'];
        })->all();
    }

    private function composeServices(bool $failOnError = false): array
    {
        try {
            $parsedCompose = $this->preview->application->parse(pull_request_id: $this->preview->pull_request_id);
            $services = data_get($parsedCompose, 'services', []);
            if (! is_iterable($services)) {
                return [];
            }

            $previewSuffix = '-pr-'.$this->preview->pull_request_id;
            $serviceNames = [];
            foreach ($services as $serviceName => $service) {
                if (isDatabaseImage(data_get($service, 'image'))) {
                    continue;
                }

                $serviceName = (string) $serviceName;
                if (str_ends_with($serviceName, $previewSuffix)) {
                    $serviceName = substr($serviceName, 0, -strlen($previewSuffix));
                }
                $serviceNames[] = $serviceName;
            }

            return array_values(array_unique($serviceNames));
        } catch (\Throwable $exception) {
            if ($failOnError) {
                throw $exception;
            }

            return [];
        }
    }
}
