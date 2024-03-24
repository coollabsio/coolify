<?php

namespace App\Livewire\Project\Application;

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
    public ?string $initialDockerComposePrLocation = null;

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
        'application.docker_compose_pr_location' => 'nullable',
        'application.docker_compose' => 'nullable',
        'application.docker_compose_pr' => 'nullable',
        'application.docker_compose_raw' => 'nullable',
        'application.docker_compose_pr_raw' => 'nullable',
        'application.dockerfile_target_build' => 'nullable',
        'application.docker_compose_custom_start_command' => 'nullable',
        'application.docker_compose_custom_build_command' => 'nullable',
        'application.custom_labels' => 'nullable',
        'application.custom_docker_run_options' => 'nullable',
        'application.pre_deployment_command' => 'nullable',
        'application.pre_deployment_command_container' => 'nullable',
        'application.post_deployment_command' => 'nullable',
        'application.post_deployment_command_container' => 'nullable',
        'application.settings.is_static' => 'boolean|required',
        'application.settings.is_raw_compose_deployment_enabled' => 'boolean|required',
        'application.settings.is_build_server_enabled' => 'boolean|required',
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
        'application.docker_compose_pr_location' => 'Docker compose location',
        'application.docker_compose' => 'Docker compose',
        'application.docker_compose_pr' => 'Docker compose',
        'application.docker_compose_raw' => 'Docker compose raw',
        'application.docker_compose_pr_raw' => 'Docker compose raw',
        'application.custom_labels' => 'Custom labels',
        'application.dockerfile_target_build' => 'Dockerfile target build',
        'application.custom_docker_run_options' => 'Custom docker run commands',
        'application.docker_compose_custom_start_command' => 'Docker compose custom start command',
        'application.docker_compose_custom_build_command' => 'Docker compose custom build command',
        'application.settings.is_static' => 'Is static',
        'application.settings.is_raw_compose_deployment_enabled' => 'Is raw compose deployment enabled',
        'application.settings.is_build_server_enabled' => 'Is build server enabled',
    ];
    public function mount()
    {
        try {
            $this->parsedServices = $this->application->parseCompose();
        } catch (\Throwable $e) {
            $this->dispatch('error', $e->getMessage());
        }
        if ($this->application->build_pack === 'dockercompose') {
            $this->application->fqdn = null;
            $this->application->settings->save();
        }
        $this->parsedServiceDomains = $this->application->docker_compose_domains ? json_decode($this->application->docker_compose_domains, true) : [];

        $this->ports_exposes = $this->application->ports_exposes;
        if (str($this->application->status)->startsWith('running') && is_null($this->application->config_hash)) {
            $this->application->isConfigurationChanged(true);
        }
        $this->isConfigurationChanged = $this->application->isConfigurationChanged();
        $this->customLabels = $this->application->parseContainerLabels();
        if (!$this->customLabels && $this->application->destination->server->proxyType() !== 'NONE') {
            $this->customLabels = str(implode("|", generateLabelsApplication($this->application)))->replace("|", "\n");
            $this->application->custom_labels = base64_encode($this->customLabels);
            $this->application->save();
        }
        $this->initialDockerComposeLocation = $this->application->docker_compose_location;
    }
    public function instantSave()
    {
        $this->application->settings->save();
        $this->dispatch('success', 'Settings saved.');
        $this->application->refresh();
        if ($this->ports_exposes !== $this->application->ports_exposes) {
            $this->resetDefaultLabels(false);
        }
    }
    public function loadComposeFile($isInit = false)
    {
        try {
            if ($isInit && $this->application->docker_compose_raw) {
                return;
            }
            ['parsedServices' => $this->parsedServices, 'initialDockerComposeLocation' => $this->initialDockerComposeLocation, 'initialDockerComposePrLocation' => $this->initialDockerComposePrLocation] = $this->application->loadComposeFile($isInit);
            $this->dispatch('success', 'Docker compose file loaded.');
        } catch (\Throwable $e) {
            $this->application->docker_compose_location = $this->initialDockerComposeLocation;
            $this->application->docker_compose_pr_location = $this->initialDockerComposePrLocation;
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
        }
        return $domain;
    }
    public function updatedApplicationBaseDirectory() {
        raY('asdf');
        if ($this->application->build_pack === 'dockercompose') {
            $this->loadComposeFile();
        }
    }
    public function updatedApplicationBuildPack()
    {
        if ($this->application->build_pack !== 'nixpacks') {
            $this->application->settings->is_static = false;
            $this->application->settings->save();
        } else {
            $this->application->ports_exposes = $this->ports_exposes = 3000;
            $this->resetDefaultLabels(false);
        }
        if ($this->application->build_pack === 'dockercompose') {
            $this->application->fqdn = null;
            $this->application->settings->save();
        }
        if ($this->application->build_pack === 'static') {
            $this->application->ports_exposes = $this->ports_exposes = 80;
            $this->resetDefaultLabels(false);
        }
        $this->submit();
        $this->dispatch('buildPackUpdated');
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
        $this->customLabels = str(implode("|", generateLabelsApplication($this->application)))->replace("|", "\n");
        $this->ports_exposes = $this->application->ports_exposes;
        $this->submit($showToaster);
    }

    public function updatedApplicationFqdn()
    {
        $this->application->fqdn = str($this->application->fqdn)->replaceEnd(',', '')->trim();
        $this->application->fqdn = str($this->application->fqdn)->replaceStart(',', '')->trim();
        $this->application->fqdn = str($this->application->fqdn)->trim()->explode(',')->map(function ($domain) {
            return str($domain)->trim()->lower();
        });
        $this->application->fqdn = $this->application->fqdn->unique()->implode(',');
        $this->application->save();
        $this->resetDefaultLabels(false);
    }
    public function submit($showToaster = true)
    {
        try {
            if (!$this->customLabels && $this->application->destination->server->proxyType() !== 'NONE') {
                $this->customLabels = str(implode("|", generateLabelsApplication($this->application)))->replace("|", "\n");
                $this->application->custom_labels = base64_encode($this->customLabels);
                $this->application->save();
            }

            if ($this->application->build_pack === 'dockercompose' && $this->initialDockerComposeLocation !== $this->application->docker_compose_location) {
                $this->loadComposeFile();
            }
            $this->validate();
            if ($this->ports_exposes !== $this->application->ports_exposes) {
                $this->resetDefaultLabels(false);
            }
            if (data_get($this->application, 'build_pack') === 'dockerimage') {
                $this->validate([
                    'application.docker_registry_image_name' => 'required',
                ]);
            }
            if (data_get($this->application, 'fqdn')) {
                $domains = str($this->application->fqdn)->trim()->explode(',');
                if ($this->application->additional_servers->count() === 0) {
                    foreach ($domains as $domain) {
                        if (!validate_dns_entry($domain, $this->application->destination->server)) {
                            $showToaster && $this->dispatch('error', "Validating DNS ($domain) failed.", "Make sure you have added the DNS records correctly.<br><br>Check this <a target='_blank' class='dark:text-white underline' href='https://coolify.io/docs/dns-settings'>documentation</a> for further help.");
                        }
                    }
                }
                check_fqdn_usage($this->application);
                $this->application->fqdn = $domains->implode(',');
            }
            if (data_get($this->application, 'custom_docker_run_options')) {
                $this->application->custom_docker_run_options = str($this->application->custom_docker_run_options)->trim();
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
            if ($this->application->build_pack === 'dockercompose') {
                $this->application->docker_compose_domains = json_encode($this->parsedServiceDomains);
                if ($this->application->settings->is_raw_compose_deployment_enabled) {
                    $this->application->parseRawCompose();
                } else {
                    $this->parsedServices = $this->application->parseCompose();
                }
            }
            $this->application->custom_labels = base64_encode($this->customLabels);
            $this->application->save();
            $showToaster && $this->dispatch('success', 'Application settings updated!');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        } finally {
            $this->isConfigurationChanged = $this->application->isConfigurationChanged();
        }
    }
}
