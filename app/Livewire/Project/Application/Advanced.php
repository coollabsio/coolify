<?php

namespace App\Livewire\Project\Application;

use App\Enums\ProxyTypes;
use App\Models\Application;
use App\Models\ApplicationSetting;
use App\Models\ServerSetting;
use App\Support\ValidationPatterns;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Validate;
use Livewire\Component;

class Advanced extends Component
{
    use AuthorizesRequests;

    /** The format that saveCustomNamePrefix() produces with str()->slug(). */
    private const CONTAINER_NAME_PREFIX_PATTERN = '/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/';

    public Application $application;

    #[Validate(['boolean'])]
    public bool $isForceHttpsEnabled = false;

    #[Validate(['boolean'])]
    public bool $isGitSubmodulesEnabled = false;

    #[Validate(['boolean'])]
    public bool $isGitLfsEnabled = false;

    #[Validate(['boolean'])]
    public bool $isGitShallowCloneEnabled = false;

    #[Validate(['boolean'])]
    public bool $isAutoDeployEnabled = true;

    #[Validate(['boolean'])]
    public bool $disableBuildCache = false;

    #[Validate(['boolean'])]
    public bool $injectBuildArgsToDockerfile = true;

    #[Validate(['boolean'])]
    public bool $includeSourceCommitInBuild = false;

    #[Validate(['boolean'])]
    public bool $isLogDrainEnabled = false;

    #[Validate(['boolean'])]
    public bool $isGpuEnabled = false;

    #[Validate(['string'])]
    public string $gpuDriver = '';

    #[Validate(['string', 'nullable'])]
    public ?string $gpuCount = null;

    #[Validate(['string', 'nullable'])]
    public ?string $gpuDeviceIds = null;

    #[Validate(['string', 'nullable'])]
    public ?string $gpuOptions = null;

    #[Validate(['string', 'nullable'])]
    public ?string $stopGracePeriod = null;

    public ?int $rollingUpdateDrainSeconds = DEFAULT_ROLLING_UPDATE_DRAIN_SECONDS;

    #[Validate(['boolean'])]
    public bool $isBuildServerEnabled = false;

    #[Validate(['boolean'])]
    public bool $isConsistentContainerNameEnabled = false;

    #[Validate(['nullable', 'string', 'max:255', 'regex:'.ValidationPatterns::CONTAINER_NAME_PATTERN])]
    public ?string $customInternalName = null;

    #[Validate(['string', 'nullable', 'max:'.ApplicationSetting::MAX_CONTAINER_NAME_PREFIX_LENGTH])]
    public ?string $customContainerNamePrefix = null;

    #[Validate(['boolean'])]
    public bool $isGzipEnabled = true;

    #[Validate(['boolean'])]
    public bool $isStripprefixEnabled = true;

    /** One of "server" (use the server setting), "none" (no resolver), or "custom". */
    public string $certificateResolverMode = 'server';

    public ?string $certificateResolverName = null;

    #[Validate(['boolean'])]
    public bool $isRawComposeDeploymentEnabled = false;

    #[Validate(['boolean'])]
    public bool $isConnectToDockerNetworkEnabled = false;

    #[Validate(['integer', 'min:0'])]
    public int $maxRestartCount = 0;

    public function mount()
    {
        try {
            $this->syncData();
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    private function syncData(bool $toModel = false): void
    {
        if ($toModel) {
            $this->validate();
            $this->validateChangedContainerNamePrefix();
            $this->application->settings->is_force_https_enabled = $this->isForceHttpsEnabled;
            $this->application->settings->is_git_submodules_enabled = $this->isGitSubmodulesEnabled;
            $this->application->settings->is_git_lfs_enabled = $this->isGitLfsEnabled;
            $this->application->settings->is_git_shallow_clone_enabled = $this->isGitShallowCloneEnabled;
            $this->application->settings->is_auto_deploy_enabled = $this->isAutoDeployEnabled;
            $this->application->settings->is_log_drain_enabled = $this->isLogDrainEnabled;
            $this->application->settings->is_gpu_enabled = $this->isGpuEnabled;
            $this->application->settings->gpu_driver = $this->gpuDriver;
            $this->application->settings->gpu_count = $this->gpuCount;
            $this->application->settings->gpu_device_ids = $this->gpuDeviceIds;
            $this->application->settings->gpu_options = $this->gpuOptions;
            $this->application->settings->is_build_server_enabled = $this->isBuildServerEnabled;
            $this->application->settings->is_consistent_container_name_enabled = $this->isConsistentContainerNameEnabled;
            $this->application->settings->custom_internal_name = $this->customInternalName;
            $this->application->settings->custom_container_name_prefix = $this->customContainerNamePrefix;
            $this->application->settings->is_gzip_enabled = $this->isGzipEnabled;
            $this->application->settings->is_stripprefix_enabled = $this->isStripprefixEnabled;
            $this->application->settings->is_raw_compose_deployment_enabled = $this->isRawComposeDeploymentEnabled;
            $this->application->settings->connect_to_docker_network = $this->isConnectToDockerNetworkEnabled;
            $this->application->settings->disable_build_cache = $this->disableBuildCache;
            $this->application->settings->inject_build_args_to_dockerfile = $this->injectBuildArgsToDockerfile;
            $this->application->settings->include_source_commit_in_build = $this->includeSourceCommitInBuild;
            $changedFields = array_keys($this->application->settings->getDirty());
            $this->application->settings->save();
            $this->auditSettingsUpdate($changedFields);
        } else {
            $this->isForceHttpsEnabled = $this->application->isForceHttpsEnabled();
            $this->isGzipEnabled = $this->application->isGzipEnabled();
            $this->isStripprefixEnabled = $this->application->isStripprefixEnabled();
            $this->syncCertificateResolverFromModel();
            $this->isLogDrainEnabled = $this->application->isLogDrainEnabled();

            $this->isGitSubmodulesEnabled = $this->application->settings->is_git_submodules_enabled;
            $this->isGitLfsEnabled = $this->application->settings->is_git_lfs_enabled;
            $this->isGitShallowCloneEnabled = $this->application->settings->is_git_shallow_clone_enabled ?? false;
            $this->isAutoDeployEnabled = $this->application->settings->is_auto_deploy_enabled;
            $this->isGpuEnabled = $this->application->settings->is_gpu_enabled;
            $this->gpuDriver = $this->application->settings->gpu_driver;
            $this->gpuCount = $this->application->settings->gpu_count;
            $this->gpuDeviceIds = $this->application->settings->gpu_device_ids;
            $this->gpuOptions = $this->application->settings->gpu_options;
            $this->isBuildServerEnabled = $this->application->settings->is_build_server_enabled;
            $this->isConsistentContainerNameEnabled = $this->application->settings->is_consistent_container_name_enabled;
            $this->customInternalName = $this->application->settings->custom_internal_name;
            $this->customContainerNamePrefix = $this->application->settings->custom_container_name_prefix;
            $this->isRawComposeDeploymentEnabled = $this->application->settings->is_raw_compose_deployment_enabled;
            $this->isConnectToDockerNetworkEnabled = $this->application->settings->connect_to_docker_network;
            $this->disableBuildCache = $this->application->settings->disable_build_cache;
            $this->injectBuildArgsToDockerfile = $this->application->settings->inject_build_args_to_dockerfile ?? true;
            $this->includeSourceCommitInBuild = $this->application->settings->include_source_commit_in_build ?? false;
            $this->maxRestartCount = $this->application->max_restart_count ?? 0;
        }

        // Load stop_grace_period separately since it has its own save handler
        // Convert null to empty string to prevent dirty detection issues
        $this->stopGracePeriod = $this->application->settings->stop_grace_period ?? '';
        $this->rollingUpdateDrainSeconds = $this->application->settings->rollingUpdateDrainSeconds();
    }

    private function syncCertificateResolverFromModel(): void
    {
        $resolver = $this->application->settings->traefik_certificate_resolver;

        $this->certificateResolverMode = match (true) {
            blank($resolver) => 'server',
            $resolver === ServerSetting::TRAEFIK_CERTIFICATE_RESOLVER_NONE => 'none',
            default => 'custom',
        };
        $this->certificateResolverName = $this->certificateResolverMode === 'custom' ? $resolver : null;
    }

    /**
     * An unchanged stored prefix is skipped, so older values do not block other settings.
     */
    private function validateChangedContainerNamePrefix(): void
    {
        $prefix = $this->customContainerNamePrefix;
        if (blank($prefix) || $prefix === $this->application->settings->custom_container_name_prefix) {
            return;
        }

        if (preg_match(self::CONTAINER_NAME_PREFIX_PATTERN, $prefix) !== 1) {
            throw ValidationException::withMessages([
                'customContainerNamePrefix' => 'The container name prefix may only contain lowercase letters, numbers, and single hyphens between them.',
            ]);
        }

        if (ApplicationSetting::isContainerNamePrefixInUse($prefix, $this->application->destination->server, $this->application->id)) {
            throw ValidationException::withMessages([
                'customContainerNamePrefix' => 'This container name prefix is already in use by another application on this Coolify instance.',
            ]);
        }
    }

    private function resetDefaultLabels()
    {
        if ($this->application->settings->is_container_label_readonly_enabled === false) {
            return;
        }
        $customLabels = str(implode('|coolify|', generateLabelsApplication($this->application)))->replace('|coolify|', "\n");
        $this->application->custom_labels = base64_encode($customLabels);
        $this->application->save();
    }

    public function instantSave()
    {
        try {
            $this->authorize('update', $this->application);
            $reset = false;
            if ($this->isLogDrainEnabled) {
                if (! $this->application->destination->server->isLogDrainEnabled()) {
                    $this->isLogDrainEnabled = false;
                    $this->syncData(true);
                    $this->dispatch('error', 'Log drain is not enabled on this server.');

                    return;
                }
            }
            if ($this->application->isForceHttpsEnabled() !== $this->isForceHttpsEnabled ||
                $this->application->isGzipEnabled() !== $this->isGzipEnabled ||
                $this->application->isStripprefixEnabled() !== $this->isStripprefixEnabled
            ) {
                $reset = true;
            }

            if ($this->application->settings->is_raw_compose_deployment_enabled) {
                $this->application->oldRawParser();
            } else {
                $this->application->parse();
            }
            $this->syncData(true);

            if ($reset) {
                $this->resetDefaultLabels();
            }

            $this->dispatch('success', 'Settings saved.');
            $this->dispatch('configurationChanged');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function submit()
    {
        try {
            $this->authorize('update', $this->application);
            // Check only changed GPU values, so stored legacy values do not block other settings.
            $settings = $this->application->settings;
            $gpuSelectionChanged = $this->gpuCount !== $settings->gpu_count || $this->gpuDeviceIds !== $settings->gpu_device_ids;
            if ($gpuSelectionChanged && filled($this->gpuCount) && filled($this->gpuDeviceIds)) {
                $this->dispatch('error', 'You cannot set both GPU count and GPU device IDs.');

                return;
            }
            if ($this->gpuCount !== $settings->gpu_count && filled($this->gpuCount) && ! preg_match(ApplicationSetting::GPU_COUNT_PATTERN, trim($this->gpuCount))) {
                $this->dispatch('error', 'GPU count must be a number or "all".');

                return;
            }
            if ($this->gpuOptions !== $settings->gpu_options && ApplicationSetting::parseGpuOptions($this->gpuOptions) === null) {
                $this->dispatch('error', 'GPU options must be key=value pairs (one per line) or a YAML mapping.');

                return;
            }
            $this->syncData(true);
            $this->dispatch('success', 'Settings saved.');
            $this->dispatch('configurationChanged');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function saveCustomName()
    {
        try {
            $this->authorize('update', $this->application);

            if (str($this->customInternalName)->isNotEmpty()) {
                $this->customInternalName = str($this->customInternalName)->slug()->value();
            } else {
                $this->customInternalName = null;
            }
            if (is_null($this->customInternalName)) {
                $this->syncData(true);
                $this->dispatch('success', 'Custom name saved.');
                $this->dispatch('configurationChanged');

                return;
            }
            $server = $this->application->destination->server;
            $allApplications = $server->applications();

            $foundSameInternalName = $allApplications->filter(function ($application) {
                return $application->id !== $this->application->id && $application->settings->custom_internal_name === $this->customInternalName;
            });
            if ($foundSameInternalName->isNotEmpty()) {
                $this->customInternalName = $this->application->settings->custom_internal_name;
                $this->dispatch('error', 'This custom container name is already in use by another application on this server.');

                return;
            }
            $this->syncData(true);
            $this->dispatch('success', 'Custom name saved.');
            $this->dispatch('configurationChanged');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function saveCustomNamePrefix()
    {
        try {
            $this->authorize('update', $this->application);

            $this->customContainerNamePrefix = str($this->customContainerNamePrefix)->slug()->value() ?: null;

            if ($this->customContainerNamePrefix && ApplicationSetting::isContainerNamePrefixInUse($this->customContainerNamePrefix, $this->application->destination->server, $this->application->id)) {
                $this->customContainerNamePrefix = $this->application->settings->custom_container_name_prefix;
                $this->dispatch('error', 'This container name prefix is already in use by another application on this Coolify instance.');

                return;
            }

            $this->syncData(true);
            $this->dispatch('success', 'Container name prefix saved.');
            $this->dispatch('configurationChanged');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    /**
     * Listbox change: "custom" waits for a resolver name before it saves.
     */
    public function saveCertificateResolverMode()
    {
        if ($this->certificateResolverMode === 'custom' && blank($this->certificateResolverName)) {
            $this->resetErrorBag('certificateResolverName');

            return;
        }

        return $this->saveCertificateResolver();
    }

    public function saveCertificateResolver()
    {
        try {
            $this->authorize('update', $this->application);

            $this->validate([
                'certificateResolverMode' => ['required', Rule::in(['server', 'none', 'custom'])],
                'certificateResolverName' => [
                    'exclude_unless:certificateResolverMode,custom',
                    'required',
                    'string',
                    'max:255',
                    'regex:'.ServerSetting::TRAEFIK_CERTIFICATE_RESOLVER_PATTERN,
                    Rule::notIn([ServerSetting::TRAEFIK_CERTIFICATE_RESOLVER_NONE]),
                ],
            ], [
                'certificateResolverName.regex' => 'The resolver name may only contain letters, numbers, hyphens, and underscores.',
                'certificateResolverName.not_in' => 'Choose "None" instead of a custom resolver named "none".',
            ], [
                'certificateResolverName' => 'resolver name',
            ]);

            $this->application->settings->traefik_certificate_resolver = match ($this->certificateResolverMode) {
                'none' => ServerSetting::TRAEFIK_CERTIFICATE_RESOLVER_NONE,
                'custom' => $this->certificateResolverName,
                default => null,
            };
            $changedFields = array_keys($this->application->settings->getDirty());
            $this->application->settings->save();
            $this->auditSettingsUpdate($changedFields);
            $this->syncCertificateResolverFromModel();

            if (in_array('traefik_certificate_resolver', $changedFields, true)) {
                $this->resetDefaultLabels();
            }

            $this->dispatch('success', 'Certificate resolver saved.');
            $this->dispatch('configurationChanged');
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function saveStopGracePeriod()
    {
        try {
            $this->authorize('update', $this->application);

            $validated = Validator::make(
                ['stopGracePeriod' => $this->stopGracePeriod === '' ? null : $this->stopGracePeriod],
                ['stopGracePeriod' => ['nullable', 'integer', 'min:'.MIN_STOP_GRACE_PERIOD_SECONDS, 'max:'.MAX_STOP_GRACE_PERIOD_SECONDS]],
                [],
                ['stopGracePeriod' => 'stop grace period']
            )->validate();

            $this->application->settings->stop_grace_period = $validated['stopGracePeriod'] === null
                ? null
                : (int) $validated['stopGracePeriod'];
            $changedFields = array_keys($this->application->settings->getDirty());
            $this->application->settings->save();
            $this->auditSettingsUpdate($changedFields);

            $this->dispatch('success', 'Stop grace period updated.');
            $this->dispatch('configurationChanged');
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function saveRollingUpdateDrainSeconds()
    {
        try {
            $this->authorize('update', $this->application);

            $validated = $this->validate(
                ['rollingUpdateDrainSeconds' => ['required', 'integer', 'min:0', 'max:'.MAX_ROLLING_UPDATE_DRAIN_SECONDS]],
                [],
                ['rollingUpdateDrainSeconds' => 'rolling update drain time']
            );

            $this->application->settings->rolling_update_drain_seconds = $validated['rollingUpdateDrainSeconds'];
            $changedFields = array_keys($this->application->settings->getDirty());
            $this->application->settings->save();
            $this->auditSettingsUpdate($changedFields);

            $this->dispatch('success', 'Rolling update drain time updated.');
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function saveMaxRestartCount()
    {
        try {
            $this->authorize('update', $this->application);
            $this->validate([
                'maxRestartCount' => 'integer|min:0',
            ]);
            $this->application->max_restart_count = $this->maxRestartCount;
            $this->application->save();
            $this->dispatch('success', 'Max restart count saved.');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function refreshGpus(): void
    {
        try {
            $servers = $this->application->maintenanceServers();
            foreach ($servers as $server) {
                $this->authorize('update', $server);
            }
            $functionalServers = $servers->filter(fn ($server) => $server->isFunctional());
            if ($functionalServers->isEmpty()) {
                $this->dispatch('error', 'Validate the server connection before you refresh GPUs.');

                return;
            }

            $failed = $functionalServers->reject(fn ($server) => $server->gatherServerMetadata())->pluck('name');
            $this->application->unsetRelation('destination')->unsetRelation('additional_servers');
            if ($failed->isNotEmpty()) {
                $this->dispatch('error', 'Could not collect GPUs from '.$failed->implode(', ').'. Check the application logs for the remote command output.');

                return;
            }
            $this->dispatch('success', 'GPUs refreshed.');
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
    }

    public function render()
    {
        $server = data_get($this->application, 'destination.server');

        return view('livewire.project.application.advanced', [
            'isTraefikProxy' => $server?->proxyType() === ProxyTypes::TRAEFIK->value,
            'serverCertificateResolver' => $server?->traefikCertificateResolver() ?? ServerSetting::TRAEFIK_CERTIFICATE_RESOLVER_NONE,
            'gpuServers' => $this->application->maintenanceServers(),
        ]);
    }

    /** @param array<int, string> $changedFields */
    private function auditSettingsUpdate(array $changedFields): void
    {
        $changedFields = array_values(array_diff($changedFields, ['updated_at']));
        if ($changedFields === []) {
            return;
        }

        auditLog('ui.application.settings_updated', [
            'team_id' => $this->application->team()?->id,
            'application_uuid' => $this->application->uuid,
            'application_name' => $this->application->name,
            'changed_fields' => $changedFields,
        ]);
    }
}
