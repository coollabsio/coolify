<?php

namespace App\Http\Livewire\Project\Application;

use App\Models\Application;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Livewire\Component;
use Visus\Cuid2\Cuid2;

class General extends Component
{
    public string $applicationId;

    public Application $application;
    public Collection $services;
    public string $name;
    public ?string $fqdn = null;
    public string $git_repository;
    public string $git_branch;
    public ?string $git_commit_sha = null;
    public string $build_pack;
    public ?string $ports_exposes = null;

    public $customLabels;
    public bool $labelsChanged = false;
    public bool $isConfigurationChanged = false;

    public ?string $initialDockerComposeLocation = null;

    public bool $is_static;

    public $parsedServices = [];
    public $parsedServiceDomains = [];

    protected $listeners = [
        'resetDefaultLabels'
    ];
    protected $rules = [
        'application.name' => 'required',
        'application.description' => 'nullable',
        'application.fqdn' => 'nullable',
        'application.git_repository' => 'required',
        'application.git_branch' => 'required',
        'application.git_commit_sha' => 'nullable',
        'application.install_command' => 'nullable',
        'application.build_command' => 'nullable',
        'application.start_command' => 'nullable',
        'application.build_pack' => 'required',
        'application.static_image' => 'required',
        'application.base_directory' => 'required',
        'application.publish_directory' => 'nullable',
        'application.ports_exposes' => 'required',
        'application.ports_mappings' => 'nullable',
        'application.dockerfile' => 'nullable',
        'application.docker_registry_image_name' => 'nullable',
        'application.docker_registry_image_tag' => 'nullable',
        'application.dockerfile_location' => 'nullable',
        'application.docker_compose_location' => 'nullable',
        'application.docker_compose' => 'nullable',
        'application.docker_compose_raw' => 'nullable',
        'application.custom_labels' => 'nullable',
        'application.dockerfile_target_build' => 'nullable',
        'application.settings.is_static' => 'boolean|required',
    ];
    protected $validationAttributes = [
        'application.name' => 'name',
        'application.description' => 'description',
        'application.fqdn' => 'FQDN',
        'application.git_repository' => 'Git repository',
        'application.git_branch' => 'Git branch',
        'application.git_commit_sha' => 'Git commit SHA',
        'application.install_command' => 'Install command',
        'application.build_command' => 'Build command',
        'application.start_command' => 'Start command',
        'application.build_pack' => 'Build pack',
        'application.static_image' => 'Static image',
        'application.base_directory' => 'Base directory',
        'application.publish_directory' => 'Publish directory',
        'application.ports_exposes' => 'Ports exposes',
        'application.ports_mappings' => 'Ports mappings',
        'application.dockerfile' => 'Dockerfile',
        'application.docker_registry_image_name' => 'Docker registry image name',
        'application.docker_registry_image_tag' => 'Docker registry image tag',
        'application.dockerfile_location' => 'Dockerfile location',
        'application.docker_compose_location' => 'Docker compose location',
        'application.docker_compose' => 'Docker compose',
        'application.docker_compose_raw' => 'Docker compose raw',
        'application.custom_labels' => 'Custom labels',
        'application.dockerfile_target_build' => 'Dockerfile target build',
        'application.settings.is_static' => 'Is static',
    ];

    public function mount()
    {
        try {
            $this->parsedServices = $this->application->parseCompose();
            ray($this->parsedServices);
        } catch (\Throwable $e) {
            $this->emit('error', $e->getMessage());
        }
        $this->parsedServiceDomains = $this->application->docker_compose_domains ? json_decode($this->application->docker_compose_domains, true) : [];

        $this->ports_exposes = $this->application->ports_exposes;
        if (str($this->application->status)->startsWith('running') && is_null($this->application->config_hash)) {
            $this->application->isConfigurationChanged(true);
        }
        $this->isConfigurationChanged = $this->application->isConfigurationChanged();
        if (is_null(data_get($this->application, 'custom_labels'))) {
            $this->customLabels = str(implode(",", generateLabelsApplication($this->application)))->replace(',', "\n");
        } else {
            $this->customLabels = str($this->application->custom_labels)->replace(',', "\n");
        }
        $this->initialDockerComposeLocation = $this->application->docker_compose_location;
        $this->checkLabelUpdates();
    }
    public function instantSave()
    {
        $this->application->settings->save();
        $this->emit('success', 'Settings saved.');
    }
    public function loadComposeFile($isInit = false)
    {
        try {
            if ($isInit && $this->application->docker_compose_raw) {
                return;
            }
            ['parsedServices' => $this->parsedServices, 'initialDockerComposeLocation' => $this->initialDockerComposeLocation] = $this->application->loadComposeFile($isInit);
            $this->emit('success', 'Docker compose file loaded.');
        } catch (\Throwable $e) {
            $this->application->docker_compose_location = $this->initialDockerComposeLocation;
            $this->application->save();
            return handleError($e, $this);
        }
    }
    public function generateDomain(string $serviceName)
    {
        $domain = $this->parsedServiceDomains[$serviceName]['domain'] ?? null;
        if (!$domain) {
            $uuid = new Cuid2(7);
            $domain = generateFqdn($this->application->destination->server, $uuid);
            $this->parsedServiceDomains[$serviceName]['domain'] = $domain;
            $this->application->docker_compose_domains = json_encode($this->parsedServiceDomains);
            $this->application->save();
            $this->emit('success', 'Domain generated.');
        }
        return $domain;
    }
    public function updatedApplicationBuildPack()
    {
        if ($this->application->build_pack !== 'nixpacks') {
            $this->application->settings->is_static = $this->is_static = false;
            $this->application->settings->save();
        }
        $this->submit();
    }
    public function checkLabelUpdates()
    {
        if (md5($this->application->custom_labels) !== md5(implode(",", generateLabelsApplication($this->application)))) {
            $this->labelsChanged = true;
        } else {
            $this->labelsChanged = false;
        }
    }

    public function getWildcardDomain()
    {
        $server = data_get($this->application, 'destination.server');
        if ($server) {
            $fqdn = generateFqdn($server, $this->application->uuid);
            $this->application->fqdn = $fqdn;
            $this->application->save();
            $this->updatedApplicationFqdn();
        }
    }
    public function resetDefaultLabels($showToaster = true)
    {
        $this->customLabels = str(implode(",", generateLabelsApplication($this->application)))->replace(',', "\n");
        $this->ports_exposes = $this->application->ports_exposes;
        $this->submit($showToaster);
    }

    public function updatedApplicationFqdn()
    {
        $this->resetDefaultLabels(false);
        $this->emit('success', 'Labels reseted to default!');
    }
    public function submit($showToaster = true)
    {
        try {
            if ($this->initialDockerComposeLocation !== $this->application->docker_compose_location) {
                $this->loadComposeFile();
            }
            $this->validate();
            if ($this->ports_exposes !== $this->application->ports_exposes) {
                $this->resetDefaultLabels(false);
            }
            if (data_get($this->application, 'build_pack') === 'dockerimage') {
                $this->validate([
                    'application.docker_registry_image_name' => 'required',
                    'application.docker_registry_image_tag' => 'required',
                ]);
            }
            if (data_get($this->application, 'fqdn')) {
                $domains = Str::of($this->application->fqdn)->trim()->explode(',')->map(function ($domain) {
                    return Str::of($domain)->trim()->lower();
                });
                $this->application->fqdn = $domains->implode(',');
            }
            if (data_get($this->application, 'dockerfile')) {
                $port = get_port_from_dockerfile($this->application->dockerfile);
                if ($port && !$this->application->ports_exposes) {
                    $this->application->ports_exposes = $port;
                }
            }
            if ($this->application->base_directory && $this->application->base_directory !== '/') {
                $this->application->base_directory = rtrim($this->application->base_directory, '/');
            }
            if ($this->application->publish_directory && $this->application->publish_directory !== '/') {
                $this->application->publish_directory = rtrim($this->application->publish_directory, '/');
            }
            if (gettype($this->customLabels) === 'string') {
                $this->customLabels = str($this->customLabels)->replace(',', "\n");
            }
            $this->application->custom_labels = $this->customLabels->explode("\n")->implode(',');
            $this->application->docker_compose_domains = json_encode($this->parsedServiceDomains);
            $this->application->save();
            $showToaster && $this->emit('success', 'Application settings updated!');
            $this->parsedServices = $this->application->parseCompose();
        } catch (\Throwable $e) {
            return handleError($e, $this);
        } finally {
            $this->checkLabelUpdates();
            $this->isConfigurationChanged = $this->application->isConfigurationChanged();
        }
    }
}
