<?php

namespace App\Livewire\Project\Application;

use App\Actions\Application\GenerateConfig;
use App\Models\Application;
use App\Support\ValidationPatterns;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Collection;
use Livewire\Attributes\Validate;
use Livewire\Component;
use Spatie\Url\Url;
use Visus\Cuid2\Cuid2;

class General extends Component
{
    use AuthorizesRequests;

    public string $applicationId;

    public Application $application;

    public Collection $services;

    #[Validate('required|regex:/^[a-zA-Z0-9\s\-_.\/:()]+$/')]
    public string $name;

    #[Validate(['string', 'nullable'])]
    public ?string $description = null;

    #[Validate(['nullable'])]
    public ?string $fqdn = null;

    #[Validate(['required'])]
    public string $gitRepository;

    #[Validate(['required'])]
    public string $gitBranch;

    #[Validate(['string', 'nullable'])]
    public ?string $gitCommitSha = null;

    #[Validate(['string', 'nullable'])]
    public ?string $installCommand = null;

    #[Validate(['string', 'nullable'])]
    public ?string $buildCommand = null;

    #[Validate(['string', 'nullable'])]
    public ?string $startCommand = null;

    #[Validate(['required'])]
    public string $buildPack;

    #[Validate(['required'])]
    public string $staticImage;

    #[Validate(['required'])]
    public string $baseDirectory;

    #[Validate(['string', 'nullable'])]
    public ?string $publishDirectory = null;

    #[Validate(['string', 'nullable'])]
    public ?string $portsExposes = null;

    #[Validate(['string', 'nullable'])]
    public ?string $portsMappings = null;

    #[Validate(['string', 'nullable'])]
    public ?string $customNetworkAliases = null;

    #[Validate(['string', 'nullable'])]
    public ?string $dockerfile = null;

    #[Validate(['string', 'nullable'])]
    public ?string $dockerfileLocation = null;

    #[Validate(['string', 'nullable'])]
    public ?string $dockerfileTargetBuild = null;

    #[Validate(['string', 'nullable'])]
    public ?string $dockerRegistryImageName = null;

    #[Validate(['string', 'nullable'])]
    public ?string $dockerRegistryImageTag = null;

    #[Validate(['string', 'nullable'])]
    public ?string $dockerComposeLocation = null;

    #[Validate(['string', 'nullable'])]
    public ?string $dockerCompose = null;

    #[Validate(['string', 'nullable'])]
    public ?string $dockerComposeRaw = null;

    #[Validate(['string', 'nullable'])]
    public ?string $dockerComposeCustomStartCommand = null;

    #[Validate(['string', 'nullable'])]
    public ?string $dockerComposeCustomBuildCommand = null;

    #[Validate(['string', 'nullable'])]
    public ?string $customDockerRunOptions = null;

    #[Validate(['string', 'nullable'])]
    public ?string $preDeploymentCommand = null;

    #[Validate(['string', 'nullable'])]
    public ?string $preDeploymentCommandContainer = null;

    #[Validate(['string', 'nullable'])]
    public ?string $postDeploymentCommand = null;

    #[Validate(['string', 'nullable'])]
    public ?string $postDeploymentCommandContainer = null;

    #[Validate(['string', 'nullable'])]
    public ?string $customNginxConfiguration = null;

    #[Validate(['boolean', 'required'])]
    public bool $isStatic = false;

    #[Validate(['boolean', 'required'])]
    public bool $isSpa = false;

    #[Validate(['boolean', 'required'])]
    public bool $isBuildServerEnabled = false;

    #[Validate(['boolean', 'required'])]
    public bool $isPreserveRepositoryEnabled = false;

    #[Validate(['boolean', 'required'])]
    public bool $isContainerLabelEscapeEnabled = true;

    #[Validate(['boolean', 'required'])]
    public bool $isContainerLabelReadonlyEnabled = false;

    #[Validate(['boolean', 'required'])]
    public bool $isHttpBasicAuthEnabled = false;

    #[Validate(['string', 'nullable'])]
    public ?string $httpBasicAuthUsername = null;

    #[Validate(['string', 'nullable'])]
    public ?string $httpBasicAuthPassword = null;

    #[Validate(['nullable'])]
    public ?string $watchPaths = null;

    #[Validate(['string', 'required'])]
    public string $redirect;

    #[Validate(['nullable'])]
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
            'gitRepository' => 'required',
            'gitBranch' => 'required',
            'gitCommitSha' => 'nullable',
            'installCommand' => 'nullable',
            'buildCommand' => 'nullable',
            'startCommand' => 'nullable',
            'buildPack' => 'required',
            'staticImage' => 'required',
            'baseDirectory' => 'required',
            'publishDirectory' => 'nullable',
            'portsExposes' => 'required',
            'portsMappings' => 'nullable',
            'customNetworkAliases' => 'nullable',
            'dockerfile' => 'nullable',
            'dockerRegistryImageName' => 'nullable',
            'dockerRegistryImageTag' => 'nullable',
            'dockerfileLocation' => 'nullable',
            'dockerComposeLocation' => 'nullable',
            'dockerCompose' => 'nullable',
            'dockerComposeRaw' => 'nullable',
            'dockerfileTargetBuild' => 'nullable',
            'dockerComposeCustomStartCommand' => 'nullable',
            'dockerComposeCustomBuildCommand' => 'nullable',
            'customLabels' => 'nullable',
            'customDockerRunOptions' => 'nullable',
            'preDeploymentCommand' => 'nullable',
            'preDeploymentCommandContainer' => 'nullable',
            'postDeploymentCommand' => 'nullable',
            'postDeploymentCommandContainer' => 'nullable',
            'customNginxConfiguration' => 'nullable',
            'isStatic' => 'boolean|required',
            'isSpa' => 'boolean|required',
            'isBuildServerEnabled' => 'boolean|required',
            'isContainerLabelEscapeEnabled' => 'boolean|required',
            'isContainerLabelReadonlyEnabled' => 'boolean|required',
            'isPreserveRepositoryEnabled' => 'boolean|required',
            'isHttpBasicAuthEnabled' => 'boolean|required',
            'httpBasicAuthUsername' => 'string|nullable',
            'httpBasicAuthPassword' => 'string|nullable',
            'watchPaths' => 'nullable',
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
                'gitRepository.required' => 'The Git Repository field is required.',
                'gitBranch.required' => 'The Git Branch field is required.',
                'buildPack.required' => 'The Build Pack field is required.',
                'staticImage.required' => 'The Static Image field is required.',
                'baseDirectory.required' => 'The Base Directory field is required.',
                'portsExposes.required' => 'The Exposed Ports field is required.',
                'isStatic.required' => 'The Static setting is required.',
                'isStatic.boolean' => 'The Static setting must be true or false.',
                'isSpa.required' => 'The SPA setting is required.',
                'isSpa.boolean' => 'The SPA setting must be true or false.',
                'isBuildServerEnabled.required' => 'The Build Server setting is required.',
                'isBuildServerEnabled.boolean' => 'The Build Server setting must be true or false.',
                'isContainerLabelEscapeEnabled.required' => 'The Container Label Escape setting is required.',
                'isContainerLabelEscapeEnabled.boolean' => 'The Container Label Escape setting must be true or false.',
                'isContainerLabelReadonlyEnabled.required' => 'The Container Label Readonly setting is required.',
                'isContainerLabelReadonlyEnabled.boolean' => 'The Container Label Readonly setting must be true or false.',
                'isPreserveRepositoryEnabled.required' => 'The Preserve Repository setting is required.',
                'isPreserveRepositoryEnabled.boolean' => 'The Preserve Repository setting must be true or false.',
                'isHttpBasicAuthEnabled.required' => 'The HTTP Basic Auth setting is required.',
                'isHttpBasicAuthEnabled.boolean' => 'The HTTP Basic Auth setting must be true or false.',
                'redirect.required' => 'The Redirect setting is required.',
                'redirect.string' => 'The Redirect setting must be a string.',
            ]
        );
    }

    protected $validationAttributes = [
        'name' => 'name',
        'description' => 'description',
        'fqdn' => 'FQDN',
        'gitRepository' => 'Git repository',
        'gitBranch' => 'Git branch',
        'gitCommitSha' => 'Git commit SHA',
        'installCommand' => 'Install command',
        'buildCommand' => 'Build command',
        'startCommand' => 'Start command',
        'buildPack' => 'Build pack',
        'staticImage' => 'Static image',
        'baseDirectory' => 'Base directory',
        'publishDirectory' => 'Publish directory',
        'portsExposes' => 'Ports exposes',
        'portsMappings' => 'Ports mappings',
        'dockerfile' => 'Dockerfile',
        'dockerRegistryImageName' => 'Docker registry image name',
        'dockerRegistryImageTag' => 'Docker registry image tag',
        'dockerfileLocation' => 'Dockerfile location',
        'dockerComposeLocation' => 'Docker compose location',
        'dockerCompose' => 'Docker compose',
        'dockerComposeRaw' => 'Docker compose raw',
        'customLabels' => 'Custom labels',
        'dockerfileTargetBuild' => 'Dockerfile target build',
        'customDockerRunOptions' => 'Custom docker run commands',
        'customNetworkAliases' => 'Custom docker network aliases',
        'dockerComposeCustomStartCommand' => 'Docker compose custom start command',
        'dockerComposeCustomBuildCommand' => 'Docker compose custom build command',
        'customNginxConfiguration' => 'Custom Nginx configuration',
        'isStatic' => 'Is static',
        'isSpa' => 'Is SPA',
        'isBuildServerEnabled' => 'Is build server enabled',
        'isContainerLabelEscapeEnabled' => 'Is container label escape enabled',
        'isContainerLabelReadonlyEnabled' => 'Is container label readonly',
        'isPreserveRepositoryEnabled' => 'Is preserve repository enabled',
        'watchPaths' => 'Watch paths',
        'redirect' => 'Redirect',
    ];

    public function mount()
    {
        try {
            $this->parsedServices = $this->application->parse();
            if (is_null($this->parsedServices) || empty($this->parsedServices)) {
                $this->dispatch('error', 'Failed to parse your docker-compose file. Please check the syntax and try again.');
                // Still sync data even if parse fails, so form fields are populated
                $this->syncData();

                return;
            }
        } catch (\Throwable $e) {
            $this->dispatch('error', $e->getMessage());
            // Still sync data even on error, so form fields are populated
            $this->syncData();
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
        $this->syncData();
    }

    public function syncData(bool $toModel = false): void
    {
        if ($toModel) {
            $this->validate();

            // Application properties
            $this->application->name = $this->name;
            $this->application->description = $this->description;
            $this->application->fqdn = $this->fqdn;
            $this->application->git_repository = $this->gitRepository;
            $this->application->git_branch = $this->gitBranch;
            $this->application->git_commit_sha = $this->gitCommitSha;
            $this->application->install_command = $this->installCommand;
            $this->application->build_command = $this->buildCommand;
            $this->application->start_command = $this->startCommand;
            $this->application->build_pack = $this->buildPack;
            $this->application->static_image = $this->staticImage;
            $this->application->base_directory = $this->baseDirectory;
            $this->application->publish_directory = $this->publishDirectory;
            $this->application->ports_exposes = $this->portsExposes;
            $this->application->ports_mappings = $this->portsMappings;
            $this->application->custom_network_aliases = $this->customNetworkAliases;
            $this->application->dockerfile = $this->dockerfile;
            $this->application->dockerfile_location = $this->dockerfileLocation;
            $this->application->dockerfile_target_build = $this->dockerfileTargetBuild;
            $this->application->docker_registry_image_name = $this->dockerRegistryImageName;
            $this->application->docker_registry_image_tag = $this->dockerRegistryImageTag;
            $this->application->docker_compose_location = $this->dockerComposeLocation;
            $this->application->docker_compose = $this->dockerCompose;
            $this->application->docker_compose_raw = $this->dockerComposeRaw;
            $this->application->docker_compose_custom_start_command = $this->dockerComposeCustomStartCommand;
            $this->application->docker_compose_custom_build_command = $this->dockerComposeCustomBuildCommand;
            $this->application->custom_labels = is_null($this->customLabels)
                ? null
                : base64_encode($this->customLabels);
            $this->application->custom_docker_run_options = $this->customDockerRunOptions;
            $this->application->pre_deployment_command = $this->preDeploymentCommand;
            $this->application->pre_deployment_command_container = $this->preDeploymentCommandContainer;
            $this->application->post_deployment_command = $this->postDeploymentCommand;
            $this->application->post_deployment_command_container = $this->postDeploymentCommandContainer;
            $this->application->custom_nginx_configuration = $this->customNginxConfiguration;
            $this->application->is_http_basic_auth_enabled = $this->isHttpBasicAuthEnabled;
            $this->application->http_basic_auth_username = $this->httpBasicAuthUsername;
            $this->application->http_basic_auth_password = $this->httpBasicAuthPassword;
            $this->application->watch_paths = $this->watchPaths;
            $this->application->redirect = $this->redirect;

            // Application settings properties
            $this->application->settings->is_static = $this->isStatic;
            $this->application->settings->is_spa = $this->isSpa;
            $this->application->settings->is_build_server_enabled = $this->isBuildServerEnabled;
            $this->application->settings->is_preserve_repository_enabled = $this->isPreserveRepositoryEnabled;
            $this->application->settings->is_container_label_escape_enabled = $this->isContainerLabelEscapeEnabled;
            $this->application->settings->is_container_label_readonly_enabled = $this->isContainerLabelReadonlyEnabled;

            $this->application->settings->save();
        } else {
            // From model to properties
            $this->name = $this->application->name;
            $this->description = $this->application->description;
            $this->fqdn = $this->application->fqdn;
            $this->gitRepository = $this->application->git_repository;
            $this->gitBranch = $this->application->git_branch;
            $this->gitCommitSha = $this->application->git_commit_sha;
            $this->installCommand = $this->application->install_command;
            $this->buildCommand = $this->application->build_command;
            $this->startCommand = $this->application->start_command;
            $this->buildPack = $this->application->build_pack;
            $this->staticImage = $this->application->static_image;
            $this->baseDirectory = $this->application->base_directory;
            $this->publishDirectory = $this->application->publish_directory;
            $this->portsExposes = $this->application->ports_exposes;
            $this->portsMappings = $this->application->ports_mappings;
            $this->customNetworkAliases = $this->application->custom_network_aliases;
            $this->dockerfile = $this->application->dockerfile;
            $this->dockerfileLocation = $this->application->dockerfile_location;
            $this->dockerfileTargetBuild = $this->application->dockerfile_target_build;
            $this->dockerRegistryImageName = $this->application->docker_registry_image_name;
            $this->dockerRegistryImageTag = $this->application->docker_registry_image_tag;
            $this->dockerComposeLocation = $this->application->docker_compose_location;
            $this->dockerCompose = $this->application->docker_compose;
            $this->dockerComposeRaw = $this->application->docker_compose_raw;
            $this->dockerComposeCustomStartCommand = $this->application->docker_compose_custom_start_command;
            $this->dockerComposeCustomBuildCommand = $this->application->docker_compose_custom_build_command;
            $this->customLabels = $this->application->parseContainerLabels();
            $this->customDockerRunOptions = $this->application->custom_docker_run_options;
            $this->preDeploymentCommand = $this->application->pre_deployment_command;
            $this->preDeploymentCommandContainer = $this->application->pre_deployment_command_container;
            $this->postDeploymentCommand = $this->application->post_deployment_command;
            $this->postDeploymentCommandContainer = $this->application->post_deployment_command_container;
            $this->customNginxConfiguration = $this->application->custom_nginx_configuration;
            $this->isHttpBasicAuthEnabled = $this->application->is_http_basic_auth_enabled;
            $this->httpBasicAuthUsername = $this->application->http_basic_auth_username;
            $this->httpBasicAuthPassword = $this->application->http_basic_auth_password;
            $this->watchPaths = $this->application->watch_paths;
            $this->redirect = $this->application->redirect;

            // Application settings properties
            $this->isStatic = $this->application->settings->is_static;
            $this->isSpa = $this->application->settings->is_spa;
            $this->isBuildServerEnabled = $this->application->settings->is_build_server_enabled;
            $this->isPreserveRepositoryEnabled = $this->application->settings->is_preserve_repository_enabled;
            $this->isContainerLabelEscapeEnabled = $this->application->settings->is_container_label_escape_enabled;
            $this->isContainerLabelReadonlyEnabled = $this->application->settings->is_container_label_readonly_enabled;
        }
    }

    public function instantSave()
    {
        try {
            $this->authorize('update', $this->application);

            $oldPortsExposes = $this->application->ports_exposes;
            $oldIsContainerLabelEscapeEnabled = $this->application->settings->is_container_label_escape_enabled;
            $oldIsPreserveRepositoryEnabled = $this->application->settings->is_preserve_repository_enabled;
            $oldIsSpa = $this->application->settings->is_spa;
            $oldIsHttpBasicAuthEnabled = $this->application->is_http_basic_auth_enabled;

            $this->syncData(toModel: true);

            if ($oldIsSpa !== $this->isSpa) {
                $this->generateNginxConfiguration($this->isSpa ? 'spa' : 'static');
            }
            if ($oldIsHttpBasicAuthEnabled !== $this->isHttpBasicAuthEnabled) {
                $this->application->save();
            }

            $this->dispatch('success', 'Settings saved.');
            $this->application->refresh();

            $this->syncData();

            // If port_exposes changed, reset default labels
            if ($oldPortsExposes !== $this->portsExposes || $oldIsContainerLabelEscapeEnabled !== $this->isContainerLabelEscapeEnabled) {
                $this->resetDefaultLabels(false);
            }
            if ($oldIsPreserveRepositoryEnabled !== $this->isPreserveRepositoryEnabled) {
                if ($this->isPreserveRepositoryEnabled === false) {
                    $this->application->fileStorages->each(function ($storage) {
                        $storage->is_based_on_git = $this->isPreserveRepositoryEnabled;
                        $storage->save();
                    });
                }
            }
            if ($this->isContainerLabelReadonlyEnabled) {
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

            // Sync the docker_compose_raw from the model to the component property
            // This ensures the Monaco editor displays the loaded compose file
            $this->syncData();

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
        if ($this->buildPack === 'dockercompose') {
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
            $this->syncData();

            return;
        }

        // Sync property to model before checking/modifying
        $this->syncData(toModel: true);

        if ($this->buildPack !== 'nixpacks') {
            $this->isStatic = false;
            $this->application->settings->is_static = false;
            $this->application->settings->save();
        } else {
            $this->resetDefaultLabels(false);
        }
        if ($this->buildPack === 'dockercompose') {
            // Only update if user has permission
            try {
                $this->authorize('update', $this->application);
                $this->fqdn = null;
                $this->application->fqdn = null;
                $this->application->settings->save();
            } catch (\Illuminate\Auth\Access\AuthorizationException $e) {
                // User doesn't have update permission, just continue without saving
            }
        }
        if ($this->buildPack === 'static') {
            $this->portsExposes = '80';
            $this->application->ports_exposes = '80';
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
                $this->syncData(toModel: true);
                $this->application->save();
                $this->application->refresh();
                $this->syncData();
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

            $this->customNginxConfiguration = defaultNginxConfiguration($type);
            $this->syncData(toModel: true);
            $this->application->save();
            $this->application->refresh();
            $this->syncData();
            $this->dispatch('success', 'Nginx configuration generated.');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function resetDefaultLabels($manualReset = false)
    {
        try {
            if (! $this->isContainerLabelReadonlyEnabled && ! $manualReset) {
                return;
            }
            $this->customLabels = str(implode('|coolify|', generateLabelsApplication($this->application)))->replace('|coolify|', "\n");
            $this->application->custom_labels = base64_encode($this->customLabels);
            $this->application->save();
            $this->application->refresh();
            $this->syncData();
            if ($this->buildPack === 'dockercompose') {
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

            $this->syncData(toModel: true);

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

            if ($this->buildPack === 'dockercompose' && $oldDockerComposeLocation !== $this->dockerComposeLocation) {
                $compose_return = $this->loadComposeFile(showToast: false);
                if ($compose_return instanceof \Livewire\Features\SupportEvents\Event) {
                    return;
                }
            }

            if ($oldPortsExposes !== $this->portsExposes || $oldIsContainerLabelEscapeEnabled !== $this->isContainerLabelEscapeEnabled) {
                $this->resetDefaultLabels();
            }
            if ($this->buildPack === 'dockerimage') {
                $this->validate([
                    'dockerRegistryImageName' => 'required',
                ]);
            }

            if ($this->customDockerRunOptions) {
                $this->customDockerRunOptions = str($this->customDockerRunOptions)->trim()->toString();
                $this->application->custom_docker_run_options = $this->customDockerRunOptions;
            }
            if ($this->dockerfile) {
                $port = get_port_from_dockerfile($this->dockerfile);
                if ($port && ! $this->portsExposes) {
                    $this->portsExposes = $port;
                    $this->application->ports_exposes = $port;
                }
            }
            if ($this->baseDirectory && $this->baseDirectory !== '/') {
                $this->baseDirectory = rtrim($this->baseDirectory, '/');
                $this->application->base_directory = $this->baseDirectory;
            }
            if ($this->publishDirectory && $this->publishDirectory !== '/') {
                $this->publishDirectory = rtrim($this->publishDirectory, '/');
                $this->application->publish_directory = $this->publishDirectory;
            }
            if ($this->buildPack === 'dockercompose') {
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
            $this->syncData();
            $showToaster && ! $warning && $this->dispatch('success', 'Application settings updated!');
        } catch (\Throwable $e) {
            $this->application->refresh();
            $this->syncData();

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

    public function getDetectedPortInfoProperty(): ?array
    {
        $detectedPort = $this->application->detectPortFromEnvironment();

        if (! $detectedPort) {
            return null;
        }

        $portsExposesArray = $this->application->ports_exposes_array;
        $isMatch = in_array($detectedPort, $portsExposesArray);
        $isEmpty = empty($portsExposesArray);

        return [
            'port' => $detectedPort,
            'matches' => $isMatch,
            'isEmpty' => $isEmpty,
        ];
    }

    public function getDockerComposeBuildCommandPreviewProperty(): string
    {
        if (! $this->dockerComposeCustomBuildCommand) {
            return '';
        }

        // Normalize baseDirectory to prevent double slashes (e.g., when baseDirectory is '/')
        $normalizedBase = $this->baseDirectory === '/' ? '' : rtrim($this->baseDirectory, '/');

        // Use relative path for clarity in preview (e.g., ./backend/docker-compose.yaml)
        // Actual deployment uses absolute path: /artifacts/{deployment_uuid}{base_directory}{docker_compose_location}
        // Build-time env path references ApplicationDeploymentJob::BUILD_TIME_ENV_PATH as source of truth
        return injectDockerComposeFlags(
            $this->dockerComposeCustomBuildCommand,
            ".{$normalizedBase}{$this->dockerComposeLocation}",
            \App\Jobs\ApplicationDeploymentJob::BUILD_TIME_ENV_PATH
        );
    }

    public function getDockerComposeStartCommandPreviewProperty(): string
    {
        if (! $this->dockerComposeCustomStartCommand) {
            return '';
        }

        // Normalize baseDirectory to prevent double slashes (e.g., when baseDirectory is '/')
        $normalizedBase = $this->baseDirectory === '/' ? '' : rtrim($this->baseDirectory, '/');

        // Use relative path for clarity in preview (e.g., ./backend/docker-compose.yaml)
        // Placeholder {workdir}/.env shows it's the workdir .env file (runtime env, not build-time)
        return injectDockerComposeFlags(
            $this->dockerComposeCustomStartCommand,
            ".{$normalizedBase}{$this->dockerComposeLocation}",
            '{workdir}/.env'
        );
    }
}
