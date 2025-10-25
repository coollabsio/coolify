<?php

namespace App\Livewire\Project\Application;

use App\Models\ApplicationPreview;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;
use Spatie\Url\Url;
use Visus\Cuid2\Cuid2;

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

            $docker_compose_domains = data_get($this->preview, 'docker_compose_domains');
            $docker_compose_domains = json_decode($docker_compose_domains, true) ?: [];
            $docker_compose_domains[$this->serviceName] = $docker_compose_domains[$this->serviceName] ?? [];
            $docker_compose_domains[$this->serviceName]['domain'] = $this->domain;
            $this->preview->docker_compose_domains = json_encode($docker_compose_domains);
            $this->preview->save();
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

            $domains = collect(json_decode($this->preview->application->docker_compose_domains, true) ?: []);
            $domain = $domains->first(function ($_, $key) {
                return $key === $this->serviceName;
            });

            $domain_string = data_get($domain, 'domain');

            // If no domain is set in the main application, generate a default domain
            if (empty($domain_string)) {
                $server = $this->preview->application->destination->server;
                $template = $this->preview->application->preview_url_template;
                $random = new Cuid2;

                // Generate a unique domain like main app services do
                $generated_fqdn = generateUrl(server: $server, random: $random);

                $preview_fqdn = str_replace('{{random}}', $random, $template);
                $preview_fqdn = str_replace('{{domain}}', str($generated_fqdn)->after('://'), $preview_fqdn);
                $preview_fqdn = str_replace('{{pr_id}}', $this->preview->pull_request_id, $preview_fqdn);
                $preview_fqdn = str($generated_fqdn)->before('://').'://'.$preview_fqdn;
            } else {
                // Use the existing domain from the main application
                // Handle multiple domains separated by commas
                $domain_list = explode(',', $domain_string);
                $preview_fqdns = [];
                $template = $this->preview->application->preview_url_template;
                $random = new Cuid2;

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
                    $preview_fqdn = str_replace('{{port}}', $port, $preview_fqdn);
                    $preview_fqdns[] = "$schema://$preview_fqdn";
                }

                $preview_fqdn = implode(',', $preview_fqdns);
            }

            // Save the generated domain
            $this->domain = $preview_fqdn;
            $docker_compose_domains = data_get($this->preview, 'docker_compose_domains');
            $docker_compose_domains = json_decode($docker_compose_domains, true) ?: [];
            $docker_compose_domains[$this->serviceName] = $docker_compose_domains[$this->serviceName] ?? [];
            $docker_compose_domains[$this->serviceName]['domain'] = $this->domain;
            $this->preview->docker_compose_domains = json_encode($docker_compose_domains);
            $this->preview->save();

            $this->dispatch('update_links');
            $this->dispatch('success', 'Domain generated.');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }
}
