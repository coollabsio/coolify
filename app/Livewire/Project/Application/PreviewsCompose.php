<?php

namespace App\Livewire\Project\Application;

use App\Models\ApplicationPreview;
use Livewire\Component;
use Spatie\Url\Url;
use Visus\Cuid2\Cuid2;

class PreviewsCompose extends Component
{
    public $service;

    public $serviceName;

    public ApplicationPreview $preview;

    public function render()
    {
        return view('livewire.project.application.previews-compose');
    }

    public function save()
    {
        $domain = data_get($this->service, 'domain');
        $docker_compose_domains = data_get($this->preview, 'docker_compose_domains');
        $docker_compose_domains = json_decode($docker_compose_domains, true);
        $docker_compose_domains[$this->serviceName]['domain'] = $domain;
        $this->preview->docker_compose_domains = json_encode($docker_compose_domains);
        $this->preview->save();
        $this->dispatch('update_links');
        $this->dispatch('success', 'Domain saved.');
    }

    public function generate()
    {
        $domains = collect(json_decode($this->preview->application->docker_compose_domains)) ?? collect();
        $domain = $domains->first(function ($_, $key) {
            return $key === $this->serviceName;
        });
        if ($domain) {
            $domain = data_get($domain, 'domain');
            $url = Url::fromString($domain);
            $template = $this->preview->application->preview_url_template;
            $host = $url->getHost();
            $schema = $url->getScheme();
            $random = new Cuid2(7);
            $preview_fqdn = str_replace('{{random}}', $random, $template);
            $preview_fqdn = str_replace('{{domain}}', $host, $preview_fqdn);
            $preview_fqdn = str_replace('{{pr_id}}', $this->preview->pull_request_id, $preview_fqdn);
            $preview_fqdn = "$schema://$preview_fqdn";
            $docker_compose_domains = data_get($this->preview, 'docker_compose_domains');
            $docker_compose_domains = json_decode($docker_compose_domains, true);
            $docker_compose_domains[$this->serviceName]['domain'] = $this->service->domain = $preview_fqdn;
            $this->preview->docker_compose_domains = json_encode($docker_compose_domains);
            $this->preview->save();
        }
        $this->dispatch('update_links');
        $this->dispatch('success', 'Domain generated.');
    }
}
