<?php

namespace App\Livewire\Project\Application;

use App\Models\ApplicationPreview;
use App\Support\ValidationPatterns;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;
use Spatie\Url\Url;

class PreviewsCompose extends Component
{
    use AuthorizesRequests;

    public $service;

    public $serviceName;

    public ApplicationPreview $preview;

    public ?string $domain = null;

    public function mount()
    {
        $this->domain = data_get($this->service, 'domain');
    }

    public function render()
    {
        return view('livewire.project.application.previews-compose');
    }

    public function save()
    {
        try {
            $this->authorize('update', $this->preview->application);
            $this->validate([
                'domain' => ValidationPatterns::applicationDomainRules(),
            ]);

            $this->domain = ValidationPatterns::normalizeApplicationDomains($this->domain);
            $this->persistPreviewDomain($this->domain);
            $this->dispatch('update_links');
            $this->dispatch('success', 'Domain saved.');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function generate()
    {
        try {
            $this->authorize('update', $this->preview->application);

            $applicationDomains = json_decode($this->preview->application->docker_compose_domains ?: '[]', true) ?: [];
            $domain_string = getComposeServiceDomainString($applicationDomains, (string) $this->serviceName);

            // If no domain is set in the main application, generate a default domain
            if (empty($domain_string)) {
                $server = $this->preview->application->destination->server;
                $template = $this->preview->application->preview_url_template;
                $random = new_public_id();

                // Generate a unique domain like main app services do
                $generated_fqdn = generateUrl(server: $server, random: $random);

                $preview_fqdn = str_replace('{{random}}', $random, $template);
                $preview_fqdn = str_replace('{{domain}}', str($generated_fqdn)->after('://'), $preview_fqdn);
                $preview_fqdn = str_replace('{{pr_id}}', $this->preview->pull_request_id, $preview_fqdn);
                $preview_fqdn = str($generated_fqdn)->before('://').'://'.$preview_fqdn;
            } else {
                foreach (ValidationPatterns::validateApplicationDomains($domain_string) as $error) {
                    throw new \InvalidArgumentException($error);
                }

                // Use the existing domain from the main application
                // Handle multiple domains separated by commas
                $domain_list = ValidationPatterns::applicationDomainList($domain_string);
                $preview_fqdns = [];
                $template = $this->preview->application->preview_url_template;
                $random = new_public_id();

                foreach ($domain_list as $single_domain) {
                    $single_domain = trim($single_domain);
                    if (empty($single_domain)) {
                        continue;
                    }

                    $url = Url::fromString($single_domain);
                    $host = $url->getHost();
                    $schema = $url->getScheme();
                    $portInt = $url->getPort();
                    $port = $portInt !== null ? ':'.$portInt : '';

                    $preview_fqdn = str_replace('{{random}}', $random, $template);
                    $preview_fqdn = str_replace('{{domain}}', $host, $preview_fqdn);
                    $preview_fqdn = str_replace('{{pr_id}}', $this->preview->pull_request_id, $preview_fqdn);
                    $preview_fqdns[] = "$schema://$preview_fqdn{$port}";
                }

                $preview_fqdn = implode(',', $preview_fqdns);
            }

            $this->domain = $preview_fqdn;
            $this->persistPreviewDomain($this->domain);

            $this->dispatch('update_links');
            $this->dispatch('success', 'Domain generated.');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    private function persistPreviewDomain(?string $domain): void
    {
        $docker_compose_domains = json_decode(data_get($this->preview, 'docker_compose_domains') ?: '[]', true) ?: [];
        $serviceNames = $this->previewServiceNames($docker_compose_domains);
        $storageKey = findComposeServiceName((string) $this->serviceName, $serviceNames)
            ?? (string) $this->serviceName;

        $docker_compose_domains = putComposeServiceDomain(
            $docker_compose_domains,
            $storageKey,
            $domain,
            $serviceNames,
        );
        $docker_compose_domains = rekeyComposeDomainsToServiceNames($docker_compose_domains, $serviceNames);

        $this->serviceName = $storageKey;
        $this->preview->docker_compose_domains = json_encode($docker_compose_domains);
        $this->preview->save();
    }

    /**
     * @param  array<string, mixed>  $previewDomains
     * @return list<string>
     */
    private function previewServiceNames(array $previewDomains): array
    {
        $parsedServices = $this->preview->application->parse(pull_request_id: $this->preview->pull_request_id);
        $fromCompose = collect(data_get($parsedServices, 'services', []))
            ->keys()
            ->map(function ($serviceName) {
                return str((string) $serviceName)
                    ->replaceLast('-pr-'.$this->preview->pull_request_id, '')
                    ->toString();
            })
            ->all();

        $domainKeys = collect(array_keys($previewDomains))
            ->merge(array_keys(json_decode($this->preview->application->docker_compose_domains ?: '[]', true) ?: []))
            ->map(fn ($name) => (string) $name);
        $unmapped = $domainKeys
            ->reject(fn (string $key) => findComposeServiceName($key, $fromCompose) !== null)
            ->all();

        return collect($fromCompose)
            ->merge(preferredComposeServiceNamesFromDomainKeys(
                $fromCompose === [] ? $domainKeys->all() : $unmapped
            ))
            ->unique()
            ->values()
            ->all();
    }
}
