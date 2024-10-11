<?php

namespace App\Livewire\Project\Application;

use App\Actions\Application\GenerateConfig;
use App\Models\Application;
use Illuminate\Support\Collection;
use Livewire\Component;
use Spatie\Url\Url;
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

    public bool $is_preserve_repository_enabled = false;

    public bool $is_container_label_escape_enabled = true;

    public $customLabels;

    public bool $labelsChanged = false;

    public bool $initLoadingCompose = false;

    public ?string $initialDockerComposeLocation = null;

    public ?Collection $parsedServices;

    public $parsedServiceDomains = [];

    protected $listeners = [
        'resetDefaultLabels',
        'configurationChanged' => '$refresh',
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
        'application.settings.is_build_server_enabled' => 'boolean|required',
        'application.settings.is_container_label_escape_enabled' => 'boolean|required',
        'application.settings.is_container_label_readonly_enabled' => 'boolean|required',
        'application.settings.is_preserve_repository_enabled' => 'boolean|required',
        'application.watch_paths' => 'nullable',
        'application.redirect' => 'string|required',
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
        'application.custom_docker_run_options' => 'Custom docker run commands',
        'application.docker_compose_custom_start_command' => 'Docker compose custom start command',
        'application.docker_compose_custom_build_command' => 'Docker compose custom build command',
        'application.settings.is_static' => 'Is static',
        'application.settings.is_build_server_enabled' => 'Is build server enabled',
        'application.settings.is_container_label_escape_enabled' => 'Is container label escape enabled',
        'application.settings.is_container_label_readonly_enabled' => 'Is container label readonly',
        'application.settings.is_preserve_repository_enabled' => 'Is preserve repository enabled',
        'application.watch_paths' => 'Watch paths',
        'application.redirect' => 'Redirect',
    ];

    public function mount()
    {
        try {
            $this->parsedServices = $this->application->parse();
            if (is_null($this->parsedServices) || empty($this->parsedServices)) {
                $this->dispatch('error', 'Failed to parse your docker-compose file. Please check the syntax and try again.');

                return;
            }
        } catch (\Throwable $e) {
            $this->dispatch('error', $e->getMessage());
        }
        if ($this->application->build_pack === 'dockercompose') {
            $this->application->fqdn = null;
            $this->application->settings->save();
        }
        $this->parsedServiceDomains = $this->application->docker_compose_domains ? json_decode($this->application->docker_compose_domains, true) : [];
        $this->ports_exposes = $this->application->ports_exposes;
        $this->is_preserve_repository_enabled = $this->application->settings->is_preserve_repository_enabled;
        $this->is_container_label_escape_enabled = $this->application->settings->is_container_label_escape_enabled;
        $this->customLabels = $this->application->parseContainerLabels();
        if (! $this->customLabels && $this->application->destination->server->proxyType() !== 'NONE' && ! $this->application->settings->is_container_label_readonly_enabled) {
            $this->customLabels = str(implode('|coolify|', generateLabelsApplication($this->application)))->replace('|coolify|', "\n");
            $this->application->custom_labels = base64_encode($this->customLabels);
            $this->application->save();
        }
        $this->initialDockerComposeLocation = $this->application->docker_compose_location;
        if ($this->application->build_pack === 'dockercompose' && ! $this->application->docker_compose_raw) {
            $this->initLoadingCompose = true;
            $this->dispatch('info', 'Loading docker compose file.');
        }

        if (str($this->application->status)->startsWith('running') && is_null($this->application->config_hash)) {
            $this->dispatch('configurationChanged');
        }
    }

    public function instantSave()
    {
        $this->application->settings->save();
        $this->dispatch('success', 'Settings saved.');
        $this->application->refresh();

        // If port_exposes changed, reset default labels
        if ($this->ports_exposes !== $this->application->ports_exposes || $this->is_container_label_escape_enabled !== $this->application->settings->is_container_label_escape_enabled) {
            $this->resetDefaultLabels(false);
        }
        if ($this->is_preserve_repository_enabled !== $this->application->settings->is_preserve_repository_enabled) {
            if ($this->application->settings->is_preserve_repository_enabled === false) {
                $this->application->fileStorages->each(function ($storage) {
                    $storage->is_based_on_git = $this->application->settings->is_preserve_repository_enabled;
                    $storage->save();
                });
            }
        }
    }

    public function loadComposeFile($isInit = false)
    {
        try {
            if ($isInit && $this->application->docker_compose_raw) {
                return;
            }

            // Must reload the application to get the latest database changes
            // Why? Not sure, but it works.
            // $this->application->refresh();

            ['parsedServices' => $this->parsedServices, 'initialDockerComposeLocation' => $this->initialDockerComposeLocation] = $this->application->loadComposeFile($isInit);
            if (is_null($this->parsedServices)) {
                $this->dispatch('error', 'Failed to parse your docker-compose file. Please check the syntax and try again.');

                return;
            }
            $this->application->parse();
            $this->dispatch('success', 'Docker compose file loaded.');
            $this->dispatch('compose_loaded');
            $this->dispatch('refreshStorages');
            $this->dispatch('refreshEnvs');
        } catch (\Throwable $e) {
            $this->application->docker_compose_location = $this->initialDockerComposeLocation;
            $this->application->save();

            return handleError($e, $this);
        } finally {
            $this->initLoadingCompose = false;
        }
    }

    public function generateDomain(string $serviceName)
    {
        $uuid = new Cuid2;
        $domain = generateFqdn($this->application->destination->server, $uuid);
        $this->parsedServiceDomains[$serviceName]['domain'] = $domain;
        $this->application->docker_compose_domains = json_encode($this->parsedServiceDomains);
        $this->application->save();
        $this->dispatch('success', 'Domain generated.');
        if ($this->application->build_pack === 'dockercompose') {
            $this->loadComposeFile();
        }

        return $domain;
    }

    public function updatedApplicationBaseDirectory()
    {
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
            $this->resetDefaultLabels();
            $this->dispatch('success', 'Wildcard domain generated.');
        }
    }

    public function resetDefaultLabels()
    {
        try {
            if ($this->application->settings->is_container_label_readonly_enabled) {
                return;
            }
            $this->customLabels = str(implode('|coolify|', generateLabelsApplication($this->application)))->replace('|coolify|', "\n");
            $this->ports_exposes = $this->application->ports_exposes;
            $this->is_container_label_escape_enabled = $this->application->settings->is_container_label_escape_enabled;
            $this->application->custom_labels = base64_encode($this->customLabels);
            $this->application->save();
            if ($this->application->build_pack === 'dockercompose') {
                $this->loadComposeFile();
            }
            $this->dispatch('configurationChanged');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function checkFqdns($showToaster = true)
    {
        if (data_get($this->application, 'fqdn')) {
            $domains = str($this->application->fqdn)->trim()->explode(',');
            if ($this->application->additional_servers->count() === 0) {
                foreach ($domains as $domain) {
                    if (! validate_dns_entry($domain, $this->application->destination->server)) {
                        $showToaster && $this->dispatch('error', 'Validating DNS failed.', "Make sure you have added the DNS records correctly.<br><br>$domain->{$this->application->destination->server->ip}<br><br>Check this <a target='_blank' class='underline dark:text-white' href='https://coolify.io/docs/knowledge-base/dns-configuration'>documentation</a> for further help.");
                    }
                }
            }
            check_domain_usage(resource: $this->application);
            $this->application->fqdn = $domains->implode(',');
        }
    }

    public function set_redirect()
    {
        try {
            $has_www = collect($this->application->fqdns)->filter(fn($fqdn) => str($fqdn)->contains('www.'))->count();
            if ($has_www === 0 && $this->application->redirect === 'www') {
                $this->dispatch('error', 'You want to redirect to www, but you do not have a www domain set.<br><br>Please add www to your domain list and as an A DNS record (if applicable).');

                return;
            }
            $this->application->save();
            $this->resetDefaultLabels();
            $this->dispatch('success', 'Redirect updated.');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function submit($showToaster = true)
    {
        try {
            $this->application->fqdn = str($this->application->fqdn)->replaceEnd(',', '')->trim();
            $this->application->fqdn = str($this->application->fqdn)->replaceStart(',', '')->trim();
            $this->application->fqdn = str($this->application->fqdn)->trim()->explode(',')->map(function ($domain) {
                Url::fromString($domain, ['http', 'https']);
                return str($domain)->trim()->lower();
            });
            $this->application->fqdn = $this->application->fqdn->unique()->implode(',');
            $this->resetDefaultLabels();

            if ($this->application->isDirty('redirect')) {
                $this->set_redirect();
            }

            $this->checkFqdns();

            $this->application->save();
            if (! $this->customLabels && $this->application->destination->server->proxyType() !== 'NONE' && ! $this->application->settings->is_container_label_readonly_enabled) {
                $this->customLabels = str(implode('|coolify|', generateLabelsApplication($this->application)))->replace('|coolify|', "\n");
                $this->application->custom_labels = base64_encode($this->customLabels);
                $this->application->save();
            }

            if ($this->application->build_pack === 'dockercompose' && $this->initialDockerComposeLocation !== $this->application->docker_compose_location) {
                $compose_return = $this->loadComposeFile();
                if ($compose_return instanceof \Livewire\Features\SupportEvents\Event) {
                    return;
                }
            }
            $this->validate();

            if ($this->ports_exposes !== $this->application->ports_exposes || $this->is_container_label_escape_enabled !== $this->application->settings->is_container_label_escape_enabled) {
                $this->resetDefaultLabels();
            }
            if (data_get($this->application, 'build_pack') === 'dockerimage') {
                $this->validate([
                    'application.docker_registry_image_name' => 'required',
                ]);
            }

            if (data_get($this->application, 'custom_docker_run_options')) {
                $this->application->custom_docker_run_options = str($this->application->custom_docker_run_options)->trim();
            }
            if (data_get($this->application, 'dockerfile')) {
                $port = get_port_from_dockerfile($this->application->dockerfile);
                if ($port && ! $this->application->ports_exposes) {
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

                foreach ($this->parsedServiceDomains as $serviceName => $service) {
                    $domain = data_get($service, 'domain');
                    if ($domain) {
                        if (! validate_dns_entry($domain, $this->application->destination->server)) {
                            $showToaster && $this->dispatch('error', 'Validating DNS failed.', "Make sure you have added the DNS records correctly.<br><br>$domain->{$this->application->destination->server->ip}<br><br>Check this <a target='_blank' class='underline dark:text-white' href='https://coolify.io/docs/knowledge-base/dns-configuration'>documentation</a> for further help.");
                        }
                        check_domain_usage(resource: $this->application);
                    }
                }
                if ($this->application->isDirty('docker_compose_domains')) {
                    $this->resetDefaultLabels();
                }
            }
            $this->application->custom_labels = base64_encode($this->customLabels);
            $this->application->save();
            $showToaster && $this->dispatch('success', 'Application settings updated!');
        } catch (\Throwable $e) {
            $originalFqdn = $this->application->getOriginal('fqdn');
            if ($originalFqdn !== $this->application->fqdn) {
                $this->application->fqdn = $originalFqdn;
            }
            return handleError($e, $this);
        } finally {
            $this->dispatch('configurationChanged');
        }
    }
    public function downloadConfig()
    {
        $config = GenerateConfig::run($this->application, true);
        $fileName = str($this->application->name)->slug()->append('_config.json');

        return response()->streamDownload(function () use ($config) {
            echo $config;
        }, $fileName, [
            'Content-Type' => 'application/json',
            'Content-Disposition' => 'attachment; filename=' . $fileName,
        ]);
    }
}
