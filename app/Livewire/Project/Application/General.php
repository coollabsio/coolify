<?php

namespace App\Livewire\Project\Application;

use App\Actions\Application\GenerateConfig;
use App\Models\Application;
use App\Support\ValidationPatterns;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Collection;
use Livewire\Component;
use Spatie\Url\Url;
use Visus\Cuid2\Cuid2;

class General extends Component
{
    use AuthorizesRequests;

    public string $applicationId;

    public Application $application;

    public Collection $services;

    public string $name;

    public ?string $description = null;

    public ?string $fqdn = null;

    public string $git_repository;

    public string $git_branch;

    public ?string $git_commit_sha = null;

    public ?string $install_command = null;

    public ?string $build_command = null;

    public ?string $start_command = null;

    public string $build_pack;

    public string $static_image;

    public string $base_directory;

    public ?string $publish_directory = null;

    public ?string $ports_exposes = null;

    public ?string $ports_mappings = null;

    public ?string $custom_network_aliases = null;

    public ?string $dockerfile = null;

    public ?string $dockerfile_location = null;

    public ?string $dockerfile_target_build = null;

    public ?string $docker_registry_image_name = null;

    public ?string $docker_registry_image_tag = null;

    public ?string $docker_compose_location = null;

    public ?string $docker_compose = null;

    public ?string $docker_compose_raw = null;

    public ?string $docker_compose_custom_start_command = null;

    public ?string $docker_compose_custom_build_command = null;

    public ?string $custom_labels = null;

    public ?string $custom_docker_run_options = null;

    public ?string $pre_deployment_command = null;

    public ?string $pre_deployment_command_container = null;

    public ?string $post_deployment_command = null;

    public ?string $post_deployment_command_container = null;

    public ?string $custom_nginx_configuration = null;

    public bool $is_static = false;

    public bool $is_spa = false;

    public bool $is_build_server_enabled = false;

    public bool $is_preserve_repository_enabled = false;

    public bool $is_container_label_escape_enabled = true;

    public bool $is_container_label_readonly_enabled = false;

    public bool $is_http_basic_auth_enabled = false;

    public ?string $http_basic_auth_username = null;

    public ?string $http_basic_auth_password = null;

    public ?string $watch_paths = null;

    public string $redirect;

    public $customLabels;

    public bool $labelsChanged = false;

    public bool $initLoadingCompose = false;

    public ?string $initialDockerComposeLocation = null;

    public ?Collection $parsedServices;

    public $parsedServiceDomains = [];

    public $domainConflicts = [];

    public $showDomainConflictModal = false;

    public $forceSaveDomains = false;

    protected $listeners = [
        'resetDefaultLabels',
        'configurationChanged' => '$refresh',
        'confirmDomainUsage',
    ];

    protected function rules(): array
    {
        return [
            'name' => ValidationPatterns::nameRules(),
            'description' => ValidationPatterns::descriptionRules(),
            'fqdn' => 'nullable',
            'git_repository' => 'required',
            'git_branch' => 'required',
            'git_commit_sha' => 'nullable',
            'install_command' => 'nullable',
            'build_command' => 'nullable',
            'start_command' => 'nullable',
            'build_pack' => 'required',
            'static_image' => 'required',
            'base_directory' => 'required',
            'publish_directory' => 'nullable',
            'ports_exposes' => 'required',
            'ports_mappings' => 'nullable',
            'custom_network_aliases' => 'nullable',
            'dockerfile' => 'nullable',
            'docker_registry_image_name' => 'nullable',
            'docker_registry_image_tag' => 'nullable',
            'dockerfile_location' => 'nullable',
            'docker_compose_location' => 'nullable',
            'docker_compose' => 'nullable',
            'docker_compose_raw' => 'nullable',
            'dockerfile_target_build' => 'nullable',
            'docker_compose_custom_start_command' => 'nullable',
            'docker_compose_custom_build_command' => 'nullable',
            'custom_labels' => 'nullable',
            'custom_docker_run_options' => 'nullable',
            'pre_deployment_command' => 'nullable',
            'pre_deployment_command_container' => 'nullable',
            'post_deployment_command' => 'nullable',
            'post_deployment_command_container' => 'nullable',
            'custom_nginx_configuration' => 'nullable',
            'is_static' => 'boolean|required',
            'is_spa' => 'boolean|required',
            'is_build_server_enabled' => 'boolean|required',
            'is_container_label_escape_enabled' => 'boolean|required',
            'is_container_label_readonly_enabled' => 'boolean|required',
            'is_preserve_repository_enabled' => 'boolean|required',
            'is_http_basic_auth_enabled' => 'boolean|required',
            'http_basic_auth_username' => 'string|nullable',
            'http_basic_auth_password' => 'string|nullable',
            'watch_paths' => 'nullable',
            'redirect' => 'string|required',
        ];
    }

    protected function messages(): array
    {
        return array_merge(
            ValidationPatterns::combinedMessages(),
            [
                'name.required' => 'The Name field is required.',
                'name.regex' => 'The Name may only contain letters, numbers, spaces, dashes (-), underscores (_), dots (.), slashes (/), colons (:), and parentheses ().',
                'description.regex' => 'The Description contains invalid characters. Only letters, numbers, spaces, and common punctuation (- _ . : / () \' " , ! ? @ # % & + = [] {} | ~ ` *) are allowed.',
                'git_repository.required' => 'The Git Repository field is required.',
                'git_branch.required' => 'The Git Branch field is required.',
                'build_pack.required' => 'The Build Pack field is required.',
                'static_image.required' => 'The Static Image field is required.',
                'base_directory.required' => 'The Base Directory field is required.',
                'ports_exposes.required' => 'The Exposed Ports field is required.',
                'is_static.required' => 'The Static setting is required.',
                'is_static.boolean' => 'The Static setting must be true or false.',
                'is_spa.required' => 'The SPA setting is required.',
                'is_spa.boolean' => 'The SPA setting must be true or false.',
                'is_build_server_enabled.required' => 'The Build Server setting is required.',
                'is_build_server_enabled.boolean' => 'The Build Server setting must be true or false.',
                'is_container_label_escape_enabled.required' => 'The Container Label Escape setting is required.',
                'is_container_label_escape_enabled.boolean' => 'The Container Label Escape setting must be true or false.',
                'is_container_label_readonly_enabled.required' => 'The Container Label Readonly setting is required.',
                'is_container_label_readonly_enabled.boolean' => 'The Container Label Readonly setting must be true or false.',
                'is_preserve_repository_enabled.required' => 'The Preserve Repository setting is required.',
                'is_preserve_repository_enabled.boolean' => 'The Preserve Repository setting must be true or false.',
                'is_http_basic_auth_enabled.required' => 'The HTTP Basic Auth setting is required.',
                'is_http_basic_auth_enabled.boolean' => 'The HTTP Basic Auth setting must be true or false.',
                'redirect.required' => 'The Redirect setting is required.',
                'redirect.string' => 'The Redirect setting must be a string.',
            ]
        );
    }

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
        'application.custom_network_aliases' => 'Custom docker network aliases',
        'application.docker_compose_custom_start_command' => 'Docker compose custom start command',
        'application.docker_compose_custom_build_command' => 'Docker compose custom build command',
        'application.custom_nginx_configuration' => 'Custom Nginx configuration',
        'application.settings.is_static' => 'Is static',
        'application.settings.is_spa' => 'Is SPA',
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
                // Still sync data even if parse fails, so form fields are populated
                $this->syncData(false);

                return;
            }
        } catch (\Throwable $e) {
            $this->dispatch('error', $e->getMessage());
            // Still sync data even on error, so form fields are populated
            $this->syncData(false);
        }
        if ($this->application->build_pack === 'dockercompose') {
            // Only update if user has permission
            try {
                $this->authorize('update', $this->application);
                $this->application->fqdn = null;
                $this->application->settings->save();
            } catch (\Illuminate\Auth\Access\AuthorizationException $e) {
                // User doesn't have update permission, just continue without saving
            }
        }
        $this->parsedServiceDomains = $this->application->docker_compose_domains ? json_decode($this->application->docker_compose_domains, true) : [];
        // Convert service names with dots and dashes to use underscores for HTML form binding
        $sanitizedDomains = [];
        foreach ($this->parsedServiceDomains as $serviceName => $domain) {
            $sanitizedKey = str($serviceName)->replace('-', '_')->replace('.', '_')->toString();
            $sanitizedDomains[$sanitizedKey] = $domain;
        }
        $this->parsedServiceDomains = $sanitizedDomains;

        $this->customLabels = $this->application->parseContainerLabels();
        if (! $this->customLabels && $this->application->destination->server->proxyType() !== 'NONE' && $this->application->settings->is_container_label_readonly_enabled === true) {
            // Only update custom labels if user has permission
            try {
                $this->authorize('update', $this->application);
                $this->customLabels = str(implode('|coolify|', generateLabelsApplication($this->application)))->replace('|coolify|', "\n");
                $this->application->custom_labels = base64_encode($this->customLabels);
                $this->application->save();
            } catch (\Illuminate\Auth\Access\AuthorizationException $e) {
                // User doesn't have update permission, just use existing labels
                // $this->customLabels = str(implode('|coolify|', generateLabelsApplication($this->application)))->replace('|coolify|', "\n");
            }
        }
        $this->initialDockerComposeLocation = $this->application->docker_compose_location;
        if ($this->application->build_pack === 'dockercompose' && ! $this->application->docker_compose_raw) {
            // Only load compose file if user has update permission
            try {
                $this->authorize('update', $this->application);
                $this->initLoadingCompose = true;
                $this->dispatch('info', 'Loading docker compose file.');
            } catch (\Illuminate\Auth\Access\AuthorizationException $e) {
                // User doesn't have update permission, skip loading compose file
            }
        }

        if (str($this->application->status)->startsWith('running') && is_null($this->application->config_hash)) {
            $this->dispatch('configurationChanged');
        }

        // Sync data from model to properties at the END, after all business logic
        // This ensures any modifications to $this->application during mount() are reflected in properties
        $this->syncData(false);
    }

    private function syncData(bool $toModel = false): void
    {
        if ($toModel) {
            $this->application->name = $this->name;
            $this->application->description = $this->description;
            $this->application->fqdn = $this->fqdn;
            $this->application->git_repository = $this->git_repository;
            $this->application->git_branch = $this->git_branch;
            $this->application->git_commit_sha = $this->git_commit_sha;
            $this->application->install_command = $this->install_command;
            $this->application->build_command = $this->build_command;
            $this->application->start_command = $this->start_command;
            $this->application->build_pack = $this->build_pack;
            $this->application->static_image = $this->static_image;
            $this->application->base_directory = $this->base_directory;
            $this->application->publish_directory = $this->publish_directory;
            $this->application->ports_exposes = $this->ports_exposes;
            $this->application->ports_mappings = $this->ports_mappings;
            $this->application->custom_network_aliases = $this->custom_network_aliases;
            $this->application->dockerfile = $this->dockerfile;
            $this->application->dockerfile_location = $this->dockerfile_location;
            $this->application->dockerfile_target_build = $this->dockerfile_target_build;
            $this->application->docker_registry_image_name = $this->docker_registry_image_name;
            $this->application->docker_registry_image_tag = $this->docker_registry_image_tag;
            $this->application->docker_compose_location = $this->docker_compose_location;
            $this->application->docker_compose = $this->docker_compose;
            $this->application->docker_compose_raw = $this->docker_compose_raw;
            $this->application->docker_compose_custom_start_command = $this->docker_compose_custom_start_command;
            $this->application->docker_compose_custom_build_command = $this->docker_compose_custom_build_command;
            $this->application->custom_labels = $this->custom_labels;
            $this->application->custom_docker_run_options = $this->custom_docker_run_options;
            $this->application->pre_deployment_command = $this->pre_deployment_command;
            $this->application->pre_deployment_command_container = $this->pre_deployment_command_container;
            $this->application->post_deployment_command = $this->post_deployment_command;
            $this->application->post_deployment_command_container = $this->post_deployment_command_container;
            $this->application->custom_nginx_configuration = $this->custom_nginx_configuration;
            $this->application->settings->is_static = $this->is_static;
            $this->application->settings->is_spa = $this->is_spa;
            $this->application->settings->is_build_server_enabled = $this->is_build_server_enabled;
            $this->application->settings->is_preserve_repository_enabled = $this->is_preserve_repository_enabled;
            $this->application->settings->is_container_label_escape_enabled = $this->is_container_label_escape_enabled;
            $this->application->settings->is_container_label_readonly_enabled = $this->is_container_label_readonly_enabled;
            $this->application->is_http_basic_auth_enabled = $this->is_http_basic_auth_enabled;
            $this->application->http_basic_auth_username = $this->http_basic_auth_username;
            $this->application->http_basic_auth_password = $this->http_basic_auth_password;
            $this->application->watch_paths = $this->watch_paths;
            $this->application->redirect = $this->redirect;
        } else {
            $this->name = $this->application->name;
            $this->description = $this->application->description;
            $this->fqdn = $this->application->fqdn;
            $this->git_repository = $this->application->git_repository;
            $this->git_branch = $this->application->git_branch;
            $this->git_commit_sha = $this->application->git_commit_sha;
            $this->install_command = $this->application->install_command;
            $this->build_command = $this->application->build_command;
            $this->start_command = $this->application->start_command;
            $this->build_pack = $this->application->build_pack;
            $this->static_image = $this->application->static_image;
            $this->base_directory = $this->application->base_directory;
            $this->publish_directory = $this->application->publish_directory;
            $this->ports_exposes = $this->application->ports_exposes;
            $this->ports_mappings = $this->application->ports_mappings;
            $this->custom_network_aliases = $this->application->custom_network_aliases;
            $this->dockerfile = $this->application->dockerfile;
            $this->dockerfile_location = $this->application->dockerfile_location;
            $this->dockerfile_target_build = $this->application->dockerfile_target_build;
            $this->docker_registry_image_name = $this->application->docker_registry_image_name;
            $this->docker_registry_image_tag = $this->application->docker_registry_image_tag;
            $this->docker_compose_location = $this->application->docker_compose_location;
            $this->docker_compose = $this->application->docker_compose;
            $this->docker_compose_raw = $this->application->docker_compose_raw;
            $this->docker_compose_custom_start_command = $this->application->docker_compose_custom_start_command;
            $this->docker_compose_custom_build_command = $this->application->docker_compose_custom_build_command;
            $this->custom_labels = $this->application->custom_labels;
            $this->custom_docker_run_options = $this->application->custom_docker_run_options;
            $this->pre_deployment_command = $this->application->pre_deployment_command;
            $this->pre_deployment_command_container = $this->application->pre_deployment_command_container;
            $this->post_deployment_command = $this->application->post_deployment_command;
            $this->post_deployment_command_container = $this->application->post_deployment_command_container;
            $this->custom_nginx_configuration = $this->application->custom_nginx_configuration;
            $this->is_static = $this->application->settings->is_static ?? false;
            $this->is_spa = $this->application->settings->is_spa ?? false;
            $this->is_build_server_enabled = $this->application->settings->is_build_server_enabled ?? false;
            $this->is_preserve_repository_enabled = $this->application->settings->is_preserve_repository_enabled ?? false;
            $this->is_container_label_escape_enabled = $this->application->settings->is_container_label_escape_enabled ?? true;
            $this->is_container_label_readonly_enabled = $this->application->settings->is_container_label_readonly_enabled ?? false;
            $this->is_http_basic_auth_enabled = $this->application->is_http_basic_auth_enabled ?? false;
            $this->http_basic_auth_username = $this->application->http_basic_auth_username;
            $this->http_basic_auth_password = $this->application->http_basic_auth_password;
            $this->watch_paths = $this->application->watch_paths;
            $this->redirect = $this->application->redirect;
        }
    }

    public function instantSave()
    {
        try {
            $this->authorize('update', $this->application);

            $oldPortsExposes = $this->application->ports_exposes;
            $oldIsContainerLabelEscapeEnabled = $this->application->settings->is_container_label_escape_enabled;
            $oldIsPreserveRepositoryEnabled = $this->application->settings->is_preserve_repository_enabled;

            $this->syncData(true);

            if ($this->application->settings->isDirty('is_spa')) {
                $this->generateNginxConfiguration($this->application->settings->is_spa ? 'spa' : 'static');
            }
            if ($this->application->isDirty('is_http_basic_auth_enabled')) {
                $this->application->save();
            }
            $this->application->settings->save();
            $this->dispatch('success', 'Settings saved.');
            $this->application->refresh();
            $this->syncData(false);

            // If port_exposes changed, reset default labels
            if ($oldPortsExposes !== $this->ports_exposes || $oldIsContainerLabelEscapeEnabled !== $this->is_container_label_escape_enabled) {
                $this->resetDefaultLabels(false);
            }
            if ($oldIsPreserveRepositoryEnabled !== $this->is_preserve_repository_enabled) {
                if ($this->is_preserve_repository_enabled === false) {
                    $this->application->fileStorages->each(function ($storage) {
                        $storage->is_based_on_git = $this->is_preserve_repository_enabled;
                        $storage->save();
                    });
                }
            }
            if ($this->is_container_label_readonly_enabled) {
                $this->resetDefaultLabels(false);
            }
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function loadComposeFile($isInit = false, $showToast = true)
    {
        try {
            $this->authorize('update', $this->application);

            if ($isInit && $this->application->docker_compose_raw) {
                return;
            }

            ['parsedServices' => $this->parsedServices, 'initialDockerComposeLocation' => $this->initialDockerComposeLocation] = $this->application->loadComposeFile($isInit);
            if (is_null($this->parsedServices)) {
                $showToast && $this->dispatch('error', 'Failed to parse your docker-compose file. Please check the syntax and try again.');

                return;
            }

            // Refresh parsedServiceDomains to reflect any changes in docker_compose_domains
            $this->application->refresh();
            $this->parsedServiceDomains = $this->application->docker_compose_domains ? json_decode($this->application->docker_compose_domains, true) : [];
            // Convert service names with dots and dashes to use underscores for HTML form binding
            $sanitizedDomains = [];
            foreach ($this->parsedServiceDomains as $serviceName => $domain) {
                $sanitizedKey = str($serviceName)->replace('-', '_')->replace('.', '_')->toString();
                $sanitizedDomains[$sanitizedKey] = $domain;
            }
            $this->parsedServiceDomains = $sanitizedDomains;

            $showToast && $this->dispatch('success', 'Docker compose file loaded.');
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
        try {
            $this->authorize('update', $this->application);

            $uuid = new Cuid2;
            $domain = generateUrl(server: $this->application->destination->server, random: $uuid);
            $sanitizedKey = str($serviceName)->replace('-', '_')->replace('.', '_')->toString();
            $this->parsedServiceDomains[$sanitizedKey]['domain'] = $domain;

            // Convert back to original service names for storage
            $originalDomains = [];
            foreach ($this->parsedServiceDomains as $key => $value) {
                // Find the original service name by checking parsed services
                $originalServiceName = $key;
                if (isset($this->parsedServices['services'])) {
                    foreach ($this->parsedServices['services'] as $originalName => $service) {
                        if (str($originalName)->replace('-', '_')->replace('.', '_')->toString() === $key) {
                            $originalServiceName = $originalName;
                            break;
                        }
                    }
                }
                $originalDomains[$originalServiceName] = $value;
            }

            $this->application->docker_compose_domains = json_encode($originalDomains);
            $this->application->save();
            $this->dispatch('success', 'Domain generated.');
            if ($this->application->build_pack === 'dockercompose') {
                $this->loadComposeFile(showToast: false);
            }

            return $domain;
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function updatedBaseDirectory()
    {
        if ($this->build_pack === 'dockercompose') {
            $this->loadComposeFile();
        }
    }

    public function updatedIsStatic($value)
    {
        if ($value) {
            $this->generateNginxConfiguration();
        }
    }

    public function updatedBuildPack()
    {
        // Check if user has permission to update
        try {
            $this->authorize('update', $this->application);
        } catch (\Illuminate\Auth\Access\AuthorizationException $e) {
            // User doesn't have permission, revert the change and return
            $this->application->refresh();
            $this->syncData(false);

            return;
        }

        // Sync property to model before checking/modifying
        $this->syncData(true);

        if ($this->build_pack !== 'nixpacks') {
            $this->is_static = false;
            $this->application->settings->is_static = false;
            $this->application->settings->save();
        } else {
            $this->ports_exposes = 3000;
            $this->application->ports_exposes = 3000;
            $this->resetDefaultLabels(false);
        }
        if ($this->build_pack === 'dockercompose') {
            // Only update if user has permission
            try {
                $this->authorize('update', $this->application);
                $this->fqdn = null;
                $this->application->fqdn = null;
                $this->application->settings->save();
            } catch (\Illuminate\Auth\Access\AuthorizationException $e) {
                // User doesn't have update permission, just continue without saving
            }
        } else {
            // Clear Docker Compose specific data when switching away from dockercompose
            if ($this->application->getOriginal('build_pack') === 'dockercompose') {
                $this->application->docker_compose_domains = null;
                $this->application->docker_compose_raw = null;

                // Remove SERVICE_FQDN_* and SERVICE_URL_* environment variables
                $this->application->environment_variables()->where('key', 'LIKE', 'SERVICE_FQDN_%')->delete();
                $this->application->environment_variables()->where('key', 'LIKE', 'SERVICE_URL_%')->delete();
                $this->application->environment_variables_preview()->where('key', 'LIKE', 'SERVICE_FQDN_%')->delete();
                $this->application->environment_variables_preview()->where('key', 'LIKE', 'SERVICE_URL_%')->delete();
            }
        }
        if ($this->build_pack === 'static') {
            $this->ports_exposes = 80;
            $this->application->ports_exposes = 80;
            $this->resetDefaultLabels(false);
            $this->generateNginxConfiguration();
        }
        $this->submit();
        $this->dispatch('buildPackUpdated');
    }

    public function getWildcardDomain()
    {
        try {
            $this->authorize('update', $this->application);

            $server = data_get($this->application, 'destination.server');
            if ($server) {
                $fqdn = generateUrl(server: $server, random: $this->application->uuid);
                $this->fqdn = $fqdn;
                $this->syncData(true);
                $this->application->save();
                $this->application->refresh();
                $this->syncData(false);
                $this->resetDefaultLabels();
                $this->dispatch('success', 'Wildcard domain generated.');
            }
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function generateNginxConfiguration($type = 'static')
    {
        try {
            $this->authorize('update', $this->application);

            $this->custom_nginx_configuration = defaultNginxConfiguration($type);
            $this->syncData(true);
            $this->application->save();
            $this->application->refresh();
            $this->syncData(false);
            $this->dispatch('success', 'Nginx configuration generated.');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function resetDefaultLabels($manualReset = false)
    {
        try {
            if (! $this->is_container_label_readonly_enabled && ! $manualReset) {
                return;
            }
            $this->customLabels = str(implode('|coolify|', generateLabelsApplication($this->application)))->replace('|coolify|', "\n");
            $this->custom_labels = base64_encode($this->customLabels);
            $this->syncData(true);
            $this->application->save();
            $this->application->refresh();
            $this->syncData(false);
            if ($this->build_pack === 'dockercompose') {
                $this->loadComposeFile(showToast: false);
            }
            $this->dispatch('configurationChanged');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function checkFqdns($showToaster = true)
    {
        if ($this->fqdn) {
            $domains = str($this->fqdn)->trim()->explode(',');
            if ($this->application->additional_servers->count() === 0) {
                foreach ($domains as $domain) {
                    if (! validateDNSEntry($domain, $this->application->destination->server)) {
                        $showToaster && $this->dispatch('error', 'Validating DNS failed.', "Make sure you have added the DNS records correctly.<br><br>$domain->{$this->application->destination->server->ip}<br><br>Check this <a target='_blank' class='underline dark:text-white' href='https://coolify.io/docs/knowledge-base/dns-configuration'>documentation</a> for further help.");
                    }
                }
            }

            // Check for domain conflicts if not forcing save
            if (! $this->forceSaveDomains) {
                $result = checkDomainUsage(resource: $this->application);
                if ($result['hasConflicts']) {
                    $this->domainConflicts = $result['conflicts'];
                    $this->showDomainConflictModal = true;

                    return false;
                }
            } else {
                // Reset the force flag after using it
                $this->forceSaveDomains = false;
            }

            $this->fqdn = $domains->implode(',');
            $this->application->fqdn = $this->fqdn;
            $this->resetDefaultLabels(false);
        }

        return true;
    }

    public function confirmDomainUsage()
    {
        $this->forceSaveDomains = true;
        $this->showDomainConflictModal = false;
        $this->submit();
    }

    public function setRedirect()
    {
        $this->authorize('update', $this->application);

        try {
            $has_www = collect($this->application->fqdns)->filter(fn ($fqdn) => str($fqdn)->contains('www.'))->count();
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
            $this->authorize('update', $this->application);

            $this->validate();

            $oldPortsExposes = $this->application->ports_exposes;
            $oldIsContainerLabelEscapeEnabled = $this->application->settings->is_container_label_escape_enabled;
            $oldDockerComposeLocation = $this->initialDockerComposeLocation;

            // Process FQDN with intermediate variable to avoid Collection/string confusion
            $this->fqdn = str($this->fqdn)->replaceEnd(',', '')->trim()->toString();
            $this->fqdn = str($this->fqdn)->replaceStart(',', '')->trim()->toString();
            $domains = str($this->fqdn)->trim()->explode(',')->map(function ($domain) {
                $domain = trim($domain);
                Url::fromString($domain, ['http', 'https']);

                return str($domain)->lower();
            });

            $this->fqdn = $domains->unique()->implode(',');
            $warning = sslipDomainWarning($this->fqdn);
            if ($warning) {
                $this->dispatch('warning', __('warning.sslipdomain'));
            }

            $this->syncData(true);

            if ($this->application->isDirty('redirect')) {
                $this->setRedirect();
            }
            if ($this->application->isDirty('dockerfile')) {
                $this->application->parseHealthcheckFromDockerfile($this->application->dockerfile);
            }

            if (! $this->checkFqdns()) {
                return; // Stop if there are conflicts and user hasn't confirmed
            }

            $this->application->save();
            if (! $this->customLabels && $this->application->destination->server->proxyType() !== 'NONE' && ! $this->application->settings->is_container_label_readonly_enabled) {
                $this->customLabels = str(implode('|coolify|', generateLabelsApplication($this->application)))->replace('|coolify|', "\n");
                $this->application->custom_labels = base64_encode($this->customLabels);
                $this->application->save();
            }

            if ($this->build_pack === 'dockercompose' && $oldDockerComposeLocation !== $this->docker_compose_location) {
                $compose_return = $this->loadComposeFile(showToast: false);
                if ($compose_return instanceof \Livewire\Features\SupportEvents\Event) {
                    return;
                }
            }

            if ($oldPortsExposes !== $this->ports_exposes || $oldIsContainerLabelEscapeEnabled !== $this->is_container_label_escape_enabled) {
                $this->resetDefaultLabels();
            }
            if ($this->build_pack === 'dockerimage') {
                $this->validate([
                    'docker_registry_image_name' => 'required',
                ]);
            }

            if ($this->custom_docker_run_options) {
                $this->custom_docker_run_options = str($this->custom_docker_run_options)->trim()->toString();
                $this->application->custom_docker_run_options = $this->custom_docker_run_options;
            }
            if ($this->dockerfile) {
                $port = get_port_from_dockerfile($this->dockerfile);
                if ($port && ! $this->ports_exposes) {
                    $this->ports_exposes = $port;
                    $this->application->ports_exposes = $port;
                }
            }
            if ($this->base_directory && $this->base_directory !== '/') {
                $this->base_directory = rtrim($this->base_directory, '/');
                $this->application->base_directory = $this->base_directory;
            }
            if ($this->publish_directory && $this->publish_directory !== '/') {
                $this->publish_directory = rtrim($this->publish_directory, '/');
                $this->application->publish_directory = $this->publish_directory;
            }
            if ($this->build_pack === 'dockercompose') {
                $this->application->docker_compose_domains = json_encode($this->parsedServiceDomains);
                if ($this->application->isDirty('docker_compose_domains')) {
                    foreach ($this->parsedServiceDomains as $service) {
                        $domain = data_get($service, 'domain');
                        if ($domain) {
                            if (! validateDNSEntry($domain, $this->application->destination->server)) {
                                $showToaster && $this->dispatch('error', 'Validating DNS failed.', "Make sure you have added the DNS records correctly.<br><br>$domain->{$this->application->destination->server->ip}<br><br>Check this <a target='_blank' class='underline dark:text-white' href='https://coolify.io/docs/knowledge-base/dns-configuration'>documentation</a> for further help.");
                            }
                        }
                    }
                    // Check for domain conflicts if not forcing save
                    if (! $this->forceSaveDomains) {
                        $result = checkDomainUsage(resource: $this->application);
                        if ($result['hasConflicts']) {
                            $this->domainConflicts = $result['conflicts'];
                            $this->showDomainConflictModal = true;

                            return;
                        }
                    } else {
                        // Reset the force flag after using it
                        $this->forceSaveDomains = false;
                    }

                    $this->application->save();
                    $this->resetDefaultLabels();
                }
            }
            $this->application->custom_labels = base64_encode($this->customLabels);
            $this->application->save();
            $this->application->refresh();
            $this->syncData(false);
            $showToaster && ! $warning && $this->dispatch('success', 'Application settings updated!');
        } catch (\Throwable $e) {
            $this->application->refresh();
            $this->syncData(false);

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
            'Content-Disposition' => 'attachment; filename='.$fileName,
        ]);
    }

    private function updateServiceEnvironmentVariables()
    {
        $domains = collect(json_decode($this->application->docker_compose_domains, true)) ?? collect([]);

        foreach ($domains as $serviceName => $service) {
            $serviceNameFormatted = str($serviceName)->upper()->replace('-', '_')->replace('.', '_');
            $domain = data_get($service, 'domain');
            // Delete SERVICE_FQDN_ and SERVICE_URL_ variables if domain is removed
            $this->application->environment_variables()->where('resourceable_type', Application::class)
                ->where('resourceable_id', $this->application->id)
                ->where('key', 'LIKE', "SERVICE_FQDN_{$serviceNameFormatted}%")
                ->delete();

            $this->application->environment_variables()->where('resourceable_type', Application::class)
                ->where('resourceable_id', $this->application->id)
                ->where('key', 'LIKE', "SERVICE_URL_{$serviceNameFormatted}%")
                ->delete();

            if ($domain) {
                // Create or update SERVICE_FQDN_ and SERVICE_URL_ variables
                $fqdn = Url::fromString($domain);
                $port = $fqdn->getPort();
                $path = $fqdn->getPath();
                $urlValue = $fqdn->getScheme().'://'.$fqdn->getHost();
                if ($path !== '/') {
                    $urlValue = $urlValue.$path;
                }
                $fqdnValue = str($domain)->after('://');
                if ($path !== '/') {
                    $fqdnValue = $fqdnValue.$path;
                }

                // Create/update SERVICE_FQDN_
                $this->application->environment_variables()->updateOrCreate([
                    'key' => "SERVICE_FQDN_{$serviceNameFormatted}",
                ], [
                    'value' => $fqdnValue,
                    'is_preview' => false,
                ]);

                // Create/update SERVICE_URL_
                $this->application->environment_variables()->updateOrCreate([
                    'key' => "SERVICE_URL_{$serviceNameFormatted}",
                ], [
                    'value' => $urlValue,
                    'is_preview' => false,
                ]);
                // Create/update port-specific variables if port exists
                if (filled($port)) {
                    $this->application->environment_variables()->updateOrCreate([
                        'key' => "SERVICE_FQDN_{$serviceNameFormatted}_{$port}",
                    ], [
                        'value' => $fqdnValue,
                        'is_preview' => false,
                    ]);

                    $this->application->environment_variables()->updateOrCreate([
                        'key' => "SERVICE_URL_{$serviceNameFormatted}_{$port}",
                    ], [
                        'value' => $urlValue,
                        'is_preview' => false,
                    ]);
                }
            }
        }
    }
}
