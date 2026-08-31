<?php

namespace App\Livewire\Project\Application;

use App\Actions\Shared\CheckDomainDns;
use App\Jobs\CheckDomainDnsJob;
use App\Models\ApplicationPreview;
use App\Support\DomainUrlParts;
use App\Support\ValidationPatterns;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\DB;
use Livewire\Component;
use Spatie\Url\Url;

class PreviewDomains extends Component
{
    use AuthorizesRequests;

    public ApplicationPreview $preview;

    public array $domainRows = [];

    public array $newDomainParts = ['scheme' => 'https', 'host' => '', 'port' => '', 'path' => ''];

    public ?string $newDomainService = null;

    public array $editingDomainParts = ['scheme' => 'https', 'host' => '', 'port' => '', 'path' => ''];

    public ?int $editingIndex = null;

    public function mount(): void
    {
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
        if (collect($this->domainRows)->contains(fn (array $row): bool => $row['url'] === $domain && $row['service'] === $this->newDomainService)) {
            $this->addError('newDomainParts.host', 'This domain is already configured.');

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
        $this->newDomainParts = DomainUrlParts::empty();
        $this->newDomainService = $this->preview->application->build_pack === 'dockercompose'
            ? ($this->composeServices()[0] ?? null)
            : null;
        $this->dispatch('close-modal');

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
                        fn (array $row): bool => $row['url'] === $domain && $row['service'] === $service
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
        if (! isset($this->domainRows[$index])) {
            return;
        }
        $this->editingIndex = $index;
        $this->editingDomainParts = DomainUrlParts::split($this->domainRows[$index]['url']);
        $this->dispatch('open-preview-domain-edit');
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
        $this->domainRows[$this->editingIndex]['url'] = $domain;
        $this->domainRows[$this->editingIndex]['dns_status'] = 'pending';
        $this->domainRows[$this->editingIndex]['dns_message'] = 'DNS has not been checked yet.';
        $index = $this->editingIndex;
        $this->editingIndex = null;
        if (! $this->persistDomains()) {
            return;
        }
        $this->dispatch('close-preview-domain-edit');
        $this->dispatch('success', 'Domain updated.');
        $this->checkDomainDns($index);
    }

    public function removeDomain(int $index): void
    {
        $this->authorize('update', $this->preview->application);
        if (! isset($this->domainRows[$index])) {
            return;
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
            $this->applyDnsCheck($index);
        }
        $this->persistDnsStatuses();
    }

    public function checkDomainDns(int $index): void
    {
        $this->authorize('update', $this->preview->application);
        $this->applyDnsCheck($index);
        $this->persistDnsStatuses();
    }

    public function pollDnsChecks(): void
    {
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
            $domains = collect($composeServices)
                ->mapWithKeys(fn (string $service): array => [$service => ['domain' => '']])
                ->all();
            $validRows = collect($this->domainRows)
                ->filter(fn (array $row): bool => in_array($row['service'] ?? null, $composeServices, true));
            foreach ($validRows->groupBy('service') as $service => $rows) {
                $domains[$service] = ['domain' => $rows->pluck('url')->implode(',')];
            }
            $this->preview->docker_compose_domains = json_encode($domains);
            $this->preview->fqdn = $validRows->pluck('url')->implode(',') ?: null;
        } else {
            $this->preview->fqdn = collect($this->domainRows)->pluck('url')->implode(',') ?: null;
        }
        $this->preview->save();
        $this->persistDnsStatuses();
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

        return [
            'url' => $url,
            'service' => $service,
            'dns_status' => $status['status'] ?? 'pending',
            'dns_message' => $status['message'] ?? 'DNS has not been checked yet.',
            'check_id' => $status['check_id'] ?? null,
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
            $url = Url::fromString($domain);
            $generatedDomain = str_replace('{{random}}', new_public_id(), $this->preview->application->preview_url_template);
            $generatedDomain = str_replace('{{domain}}', $url->getHost(), $generatedDomain);
            $generatedDomain = str_replace('{{pr_id}}', (string) $this->preview->pull_request_id, $generatedDomain);
            $port = $url->getPort() !== null ? ':'.$url->getPort() : '';
            $path = ! in_array($url->getPath(), ['', '/'], true) ? $url->getPath() : '';

            return "{$url->getScheme()}://{$generatedDomain}{$port}{$path}";
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

            $usesLegacyServiceKeys = (int) $this->preview->application->compose_parsing_version < 3;
            $previewSuffix = '-pr-'.$this->preview->pull_request_id;
            $serviceNames = [];
            foreach ($services as $serviceName => $service) {
                if (isDatabaseImage(data_get($service, 'image'))) {
                    continue;
                }

                $serviceName = (string) $serviceName;
                if ($usesLegacyServiceKeys && str_ends_with($serviceName, $previewSuffix)) {
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
