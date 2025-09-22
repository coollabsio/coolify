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

    public function render()
    {
        return view('livewire.project.application.previews-compose');
    }

    public function save()
    {
        try {
            $this->authorize('update', $this->preview->application);

            $domain = data_get($this->service, 'domain');
            $docker_compose_domains = data_get($this->preview, 'docker_compose_domains');
            $docker_compose_domains = json_decode($docker_compose_domains, true);
            $docker_compose_domains[$this->serviceName]['domain'] = $domain;
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

            $domains = collect(json_decode($this->preview->application->docker_compose_domains)) ?? collect();
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
                $url = Url::fromString($domain_string);
                $template = $this->preview->application->preview_url_template;
                $host = $url->getHost();
                $schema = $url->getScheme();
                $random = new Cuid2;
                $preview_fqdn = str_replace('{{random}}', $random, $template);
                $preview_fqdn = str_replace('{{domain}}', $host, $preview_fqdn);
                $preview_fqdn = str_replace('{{pr_id}}', $this->preview->pull_request_id, $preview_fqdn);
                $preview_fqdn = "$schema://$preview_fqdn";
            }

            // Save the generated domain
            $docker_compose_domains = data_get($this->preview, 'docker_compose_domains');
            $docker_compose_domains = json_decode($docker_compose_domains, true);
            $docker_compose_domains[$this->serviceName]['domain'] = $this->service->domain = $preview_fqdn;
            $this->preview->docker_compose_domains = json_encode($docker_compose_domains);
            $this->preview->save();

            $this->dispatch('update_links');
            $this->dispatch('success', 'Domain generated.');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }
}
