<?php

namespace App\Livewire\Project\ClusterApplication;

use App\Actions\Node\Build\CancelNodeBuild;
use App\Actions\Node\Build\CreateClusterInlineDockerfileWorkload;
use App\Actions\Node\Build\StartNodeBuild;
use App\Actions\Node\CreateClusterDatabaseWorkload;
use App\Actions\Node\CreateClusterDockerImageWorkload;
use App\Actions\Node\CreateDeploymentOperation;
use App\Actions\Node\CreateLifecycleOperation;
use App\Actions\Node\CreateMoveOperation;
use App\Actions\Node\CreateNodeVolume;
use App\Actions\Node\DeleteNodeVolumeCopy;
use App\Actions\Node\DeleteNodeVolumeData;
use App\Actions\Node\DetermineWorkloadState;
use App\Actions\Node\NotifyNodeReachability;
use App\Actions\Node\PrepareNodeWorkloadRevision;
use App\Actions\Node\RemoveNodeVolume;
use App\Actions\Node\UpdateNodeWorkloadConfiguration;
use App\Actions\Node\UpdateNodeWorkloadDomains;
use App\Actions\Node\UpdateNodeWorkloadResources;
use App\Enums\NodeBuildStatus;
use App\Enums\NodeOperationStatus;
use App\Enums\NodeVolumeCopyState;
use App\Enums\NodeWorkloadAction;
use App\Enums\NodeWorkloadSourceType;
use App\Enums\NodeWorkloadState;
use App\Jobs\DeployNodeWorkloadJob;
use App\Jobs\ManageNodeWorkloadJob;
use App\Jobs\MoveNodeWorkloadJob;
use App\Livewire\Project\Shared\ConfigurationChecker;
use App\Models\Environment;
use App\Models\Node;
use App\Models\NodeBuild;
use App\Models\NodeBuildLogLine;
use App\Models\NodeCluster;
use App\Models\NodeContainer;
use App\Models\NodeOperation;
use App\Models\NodeRegistry;
use App\Models\NodeVolume;
use App\Models\NodeVolumeCopy;
use App\Models\NodeWorkload;
use App\Models\Project;
use App\Models\RegistryCredential;
use App\Rules\DockerImageFormat;
use App\Rules\ValidGitBranch;
use App\Services\DockerImageParser;
use App\Services\DockerRegistryLogins;
use App\Support\DomainUrlParts;
use App\Support\ValidationPatterns;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

class Show extends Component
{
    use AuthorizesRequests;

    /** @var list<string> */
    public const SECTIONS = ['general', 'domains', 'environment-variables', 'storage', 'healthcheck', 'resource-limits', 'deployments', 'deployment', 'build', 'logs', 'servers', 'webhooks', 'backups', 'scheduled-tasks', 'terminal', 'danger'];

    /** @var list<string> */
    public const DEPLOYMENT_COMMAND_TYPES = NodeOperation::DEPLOYMENT_COMMAND_TYPES;

    public const DEPLOYMENTS_PER_PAGE = 10;

    #[Locked]
    public string $section = 'general';

    #[Locked]
    public ?string $deploymentUuid = null;

    #[Locked]
    public ?string $buildUuid = null;

    public int $deploymentPage = 1;

    public string $deploymentSearch = '';

    /** @var list<string> Selected filters, like v4: `status:success` or `server:<node uuid>`. */
    public array $deploymentFilters = [];

    public string $deploymentSort = 'newest';

    public Project $project;

    public Environment $environment;

    public NodeWorkload $workload;

    /** The first server of the application, or null when no server runs it. */
    public ?Node $node = null;

    public string $status = 'Unknown';

    public string $statusType = 'neutral';

    /** Whether any server still has a container of this application, like v4 `container_present`. */
    public bool $containerPresent = false;

    public string $name = '';

    public ?string $description = null;

    public string $cpuLimit = '';

    public string $cpuReservation = '';

    public string $memoryLimitMb = '';

    public string $memoryReservationMb = '';

    public string $imageName = '';

    public string $imageTag = '';

    public string $startCommand = '';

    public string $gitBranch = '';

    public string $baseDirectory = '';

    public string $dockerfileLocation = '';

    /** The Dockerfile of an application without Git. */
    public string $dockerfile = '';

    public string $buildPack = '';

    /** The UUID of the registry credential, or empty for none. */
    public string $registryCredentialUuid = '';

    public string $dockerRegistryImageName = '';

    public string $dockerRegistryImageTag = '';

    /** @var array{scheme: string, host: string, port: string, path: string} */
    public array $newDomainParts = ['scheme' => 'https', 'host' => '', 'port' => '', 'path' => ''];

    public bool $showEditDomainModal = false;

    /** Host and path of the domain that the settings modal edits. */
    #[Locked]
    public ?array $editingRoute = null;

    /** @var array{scheme: string, host: string, port: string, path: string} */
    public array $editingDomainParts = ['scheme' => 'https', 'host' => '', 'port' => '', 'path' => ''];

    public string $editingIndexing = 'index';

    public string $editingRedirect = 'both';

    public bool $forceHttps = true;

    public bool $permanentRedirect = true;

    public string $newVolumeName = '';

    public string $newVolumeMountPath = '';

    /** Uuid of the server to move the application from. */
    public string $moveSource = '';

    /** Uuid of the server to move the application to. */
    public string $moveTarget = '';

    /** The user accepts that a recovery move from an offline server restores the volumes from their backups. */
    public bool $moveRecoveryConfirmed = false;

    /** The full image reference of a database, like the v4 database Image field. */
    public string $databaseImage = '';

    /** @var array<string, string> The credential variables of a database, keyed by variable name. Empty for users who cannot update it. */
    public array $databaseCredentials = [];

    /** @var list<string> Credential variables that are locked (shown once), so the page cannot show or change them. */
    #[Locked]
    public array $lockedDatabaseCredentials = [];

    public function mount(string $project_uuid, string $environment_uuid, string $workload_uuid, ?string $deployment_uuid = null, ?string $build_uuid = null): void
    {
        abort_unless(nodesEnabled(), 404);
        $this->project = Project::query()->where('team_id', currentTeam()->id)->where('uuid', $project_uuid)->firstOrFail();
        $this->environment = $this->project->environments()->where('uuid', $environment_uuid)->firstOrFail();
        $this->workload = NodeWorkload::query()->where('team_id', currentTeam()->id)
            ->where('project_id', $this->project->id)->where('environment_id', $this->environment->id)
            ->where('uuid', $workload_uuid)->firstOrFail();
        $this->authorize('view', $this->workload);
        $this->section = $this->resolveSection(request()->route()?->getName());
        if ($this->section === 'deployment') {
            $this->deploymentUuid = $this->deploymentQuery()->where('uuid', $deployment_uuid)->firstOrFail()->uuid;
        }
        if ($this->section === 'build') {
            $this->buildUuid = $this->workload->builds()->where('uuid', $build_uuid)->firstOrFail()->uuid;
        }
        $this->loadData();
        $this->loadDetails();
        $this->loadResourceSettings();
        $this->loadConfiguration();
        $this->loadDomains();
        $this->resetNewDomain();
        // A database has no public domain (decision 0010).
        abort_if($this->section === 'domains' && $this->workload->isDatabase(), 404);
        $this->loadDatabase();
    }

    /**
     * Deploys the latest configuration on every server of the application and opens the
     * deployment log, like the v4 application heading.
     */
    public function deploy(): void
    {
        $operation = null;
        try {
            $this->authorize('update', $this->workload);
            if (! $this->ensureHasServer()) {
                return;
            }
            // A Git application deploys through a build: its job publishes the revision and deploys it.
            if ($this->workload->buildsFromSource()) {
                $build = StartNodeBuild::run($this->workload, auth()->user());
                redirectRoute($this, 'project.cluster-application.build.show', [
                    'project_uuid' => $this->project->uuid,
                    'environment_uuid' => $this->environment->uuid,
                    'workload_uuid' => $this->workload->uuid,
                    'build_uuid' => $build->uuid,
                ]);

                return;
            }
            $revision = PrepareNodeWorkloadRevision::run($this->workload);
            foreach ($this->workload->nodes()->orderBy('nodes.id')->get() as $node) {
                $deployment = CreateDeploymentOperation::run($node, $revision, auth()->user(), 'newer');
                if ($deployment['created']) {
                    DeployNodeWorkloadJob::dispatch($deployment['operation']->id);
                    $operation ??= $deployment['operation'];
                } elseif (in_array($deployment['operation']->command_type, self::DEPLOYMENT_COMMAND_TYPES, true)) {
                    $operation ??= $deployment['operation'];
                }
            }
        } catch (\Throwable $exception) {
            handleError($exception, $this);
            $this->loadData();

            return;
        }
        if ($operation === null) {
            $this->dispatch('info', 'This application already has an active operation.');
            $this->loadData();

            return;
        }

        redirectRoute($this, 'project.cluster-application.deployment.show', [
            'project_uuid' => $this->project->uuid,
            'environment_uuid' => $this->environment->uuid,
            'workload_uuid' => $this->workload->uuid,
            'deployment_uuid' => $operation->uuid,
        ]);
    }

    public function cancelBuild(): void
    {
        $this->authorize('update', $this->workload);
        $build = $this->workload->builds()->where('uuid', $this->buildUuid)->firstOrFail();
        CancelNodeBuild::run($build);
        $this->dispatch('success', 'Build cancelled.');
    }

    public function refresh(): void
    {
        $this->authorize('view', $this->workload);
        $this->loadData();
        $this->dispatch('configurationChanged')->to(ConfigurationChecker::class);
    }

    public function saveResources(): void
    {
        $this->authorize('update', $this->workload);
        $validated = $this->validate([
            'cpuLimit' => ['nullable', 'numeric', 'between:0.01,1024'],
            'cpuReservation' => ['nullable', 'numeric', 'between:0.01,1024'],
            'memoryLimitMb' => ['nullable', 'integer', 'between:4,1048576'],
            'memoryReservationMb' => ['nullable', 'integer', 'between:4,1048576'],
        ]);
        if (filled($validated['cpuLimit']) && filled($validated['cpuReservation']) && (float) $validated['cpuReservation'] > (float) $validated['cpuLimit']) {
            $this->addError('cpuReservation', 'CPU reservation cannot be greater than the CPU limit.');
        }
        if (filled($validated['memoryLimitMb']) && filled($validated['memoryReservationMb']) && (int) $validated['memoryReservationMb'] > (int) $validated['memoryLimitMb']) {
            $this->addError('memoryReservationMb', 'Memory reservation cannot be greater than the memory limit.');
        }
        if ($this->getErrorBag()->isNotEmpty()) {
            return;
        }

        UpdateNodeWorkloadResources::run($this->workload, [
            'cpu_limit' => filled($validated['cpuLimit']) ? (float) $validated['cpuLimit'] : null,
            'cpu_reservation' => filled($validated['cpuReservation']) ? (float) $validated['cpuReservation'] : null,
            'memory_limit_bytes' => filled($validated['memoryLimitMb']) ? (int) $validated['memoryLimitMb'] * 1_048_576 : null,
            'memory_reservation_bytes' => filled($validated['memoryReservationMb']) ? (int) $validated['memoryReservationMb'] * 1_048_576 : null,
        ]);
        $this->loadData();
        $this->loadResourceSettings();
        $this->dispatch('configurationChanged')->to(ConfigurationChecker::class);
        $this->dispatch('success', 'Resource settings saved. A running application applies them automatically within about a minute, or redeploy to apply them now.');
    }

    public function saveConfiguration(): void
    {
        $this->authorize('update', $this->workload);
        if ($this->workload->buildsFromSource()) {
            $this->saveSourceConfiguration();

            return;
        }
        $this->validate([
            'imageName' => ValidationPatterns::dockerImageNameRules(required: true),
            'imageTag' => ValidationPatterns::dockerImageTagRules(),
            'startCommand' => ['nullable', 'string', 'max:16384'],
        ]);
        $command = $this->parseStartCommand();
        if ($this->getErrorBag()->isNotEmpty()) {
            return;
        }
        $tag = filled($this->imageTag) ? trim($this->imageTag) : 'latest';
        $image = CreateClusterDockerImageWorkload::qualifiedImage(
            trim($this->imageName).(preg_match('/^[a-f0-9]{64}$/i', $tag) === 1 ? '@sha256:' : ':').$tag,
        );
        $credential = $this->selectedRegistryCredential($image, 'registryCredentialUuid');
        if ($this->getErrorBag()->isNotEmpty()) {
            return;
        }

        $this->workload->update(['registry_credential_id' => $credential?->id]);
        UpdateNodeWorkloadConfiguration::run($this->workload, $command, null, $image);
        $this->loadData();
        $this->loadConfiguration();
        $this->dispatch('configurationChanged')->to(ConfigurationChecker::class);
        $this->dispatch('success', 'Configuration saved. A running application applies it automatically within about a minute, or redeploy to apply it now.');
    }

    public function addDomain(): void
    {
        $this->authorize('update', $this->workload);
        $routes = [...($this->workload->domain_routes ?? []), $this->routeFromParts($this->newDomainParts, false)];
        if ($this->persistDomains($routes, 'newDomainParts.host')) {
            $this->resetNewDomain();
            $this->dispatch('close-modal');
            $this->dispatch('success', 'Domain added. Ingress servers apply it without a redeploy.');
        }
    }

    /** Fills the domain field of the add form, or of the edit form when $forEdit is true. */
    public function generateDomain(bool $forEdit = false): void
    {
        $this->authorize('update', $this->workload);
        $field = $forEdit ? 'editingDomainParts' : 'newDomainParts';
        $suggestedDomain = $this->suggestedDomain($this->ingressAddresses());
        if ($suggestedDomain === null) {
            $this->addError("{$field}.host", 'Turn on ingress for a server of the cluster to generate a domain.');

            return;
        }
        $this->{$field}['host'] = $suggestedDomain;
    }

    public function editDomain(string $host, string $path = ''): void
    {
        $this->authorize('update', $this->workload);
        $route = collect($this->workload->domain_routes ?? [])->first(fn (array $route): bool => $route['host'] === $host && $route['path'] === $path);
        if ($route === null) {
            return;
        }
        $this->resetErrorBag();
        $this->editingRoute = ['host' => $host, 'path' => $path];
        $this->editingDomainParts = ['scheme' => $route['scheme'], 'host' => $host, 'port' => (string) $route['port'], 'path' => $path];
        $this->editingIndexing = ($route['noindex'] ?? false) ? 'noindex' : 'index';
        $this->editingRedirect = $this->workload->www_redirect;
        $this->showEditDomainModal = true;
    }

    public function updateDomain(): void
    {
        $this->authorize('update', $this->workload);
        $routes = collect($this->workload->domain_routes ?? [])
            ->map(fn (array $route): array => $route['host'] === ($this->editingRoute['host'] ?? null) && $route['path'] === ($this->editingRoute['path'] ?? null)
                ? $this->routeFromParts($this->editingDomainParts, $this->editingIndexing === 'noindex')
                : $route)
            ->all();
        if ($this->persistDomains($routes, 'editingDomainParts.host', ['www_redirect' => $this->editingRedirect])) {
            $this->showEditDomainModal = false;
            $this->dispatch('success', 'Domain saved. Ingress servers apply it without a redeploy.');
        }
    }

    public function removeDomain(string $host, string $path = ''): void
    {
        $this->authorize('update', $this->workload);
        $routes = collect($this->workload->domain_routes ?? [])
            ->reject(fn (array $route): bool => $route['host'] === $host && $route['path'] === $path)
            ->values()
            ->all();
        if ($this->persistDomains($routes, 'newDomainParts.host')) {
            $this->dispatch('success', 'Domain removed.');
        }
    }

    public function updateForceHttps(): void
    {
        $this->authorize('update', $this->workload);
        if ($this->persistDomains($this->workload->domain_routes ?? [], 'forceHttps', ['force_https' => $this->forceHttps])) {
            $this->dispatch('success', 'Redirect saved. Ingress servers apply it without a redeploy.');
        }
    }

    public function updatePermanentRedirect(): void
    {
        $this->authorize('update', $this->workload);
        if ($this->persistDomains($this->workload->domain_routes ?? [], 'permanentRedirect', ['permanent_redirect' => $this->permanentRedirect])) {
            $this->dispatch('success', 'Redirect saved. Ingress servers apply it without a redeploy.');
        }
    }

    /**
     * A full URL pasted into the domain field fills the other fields, like in v4.
     *
     * @param  array{scheme?: string, host?: string, port?: string, path?: string}  $parts
     * @return array{scheme: string, host: string, port: string, path: string, noindex: bool}
     */
    private function routeFromParts(array $parts, bool $noindex): array
    {
        $host = trim((string) ($parts['host'] ?? ''));
        if (str_contains($host, '://')) {
            $url = DomainUrlParts::split($host);
            $parts = [
                'scheme' => $url['scheme'],
                'host' => $url['host'],
                'port' => $url['port'] !== '' ? $url['port'] : ($parts['port'] ?? ''),
                'path' => $url['path'] !== '' ? $url['path'] : ($parts['path'] ?? ''),
            ];
        }

        return [
            'scheme' => (string) ($parts['scheme'] ?? 'https'),
            'host' => (string) ($parts['host'] ?? ''),
            'port' => (string) ($parts['port'] ?? ''),
            'path' => (string) ($parts['path'] ?? ''),
            'noindex' => $noindex,
        ];
    }

    /**
     * Saves the full domain list and changed redirect settings. Validation errors go to the given field.
     *
     * @param  list<array<string, mixed>>  $routes
     * @param  array{www_redirect?: string, force_https?: bool, permanent_redirect?: bool}  $redirects
     */
    private function persistDomains(array $routes, string $errorField, array $redirects = []): bool
    {
        try {
            $this->workload = UpdateNodeWorkloadDomains::run($this->workload, $routes, auth()->user(), $redirects);
        } catch (ValidationException $exception) {
            $this->addError($errorField, collect($exception->errors())->flatten()->first());
            $this->loadDomains();

            return false;
        }
        $this->loadData();
        $this->loadDomains();

        return true;
    }

    public function addVolume(): void
    {
        $this->authorize('update', $this->workload);
        $validated = $this->validate([
            'newVolumeName' => ['required', 'string', 'max:255', 'regex:/\A[\pL\pN ._-]+\z/u'],
            'newVolumeMountPath' => ['required', 'string', 'max:4096'],
        ], [
            'newVolumeName.regex' => 'Use letters, numbers, spaces, dots, dashes, and underscores.',
        ]);
        try {
            CreateNodeVolume::run($this->workload, $validated['newVolumeName'], $validated['newVolumeMountPath']);
        } catch (ValidationException $exception) {
            $this->addError('newVolumeMountPath', collect($exception->errors())->flatten()->first());

            return;
        }
        $this->newVolumeName = '';
        $this->newVolumeMountPath = '';
        $this->dispatch('close-modal');
        $this->dispatch('configurationChanged')->to(ConfigurationChecker::class);
        $this->dispatch('success', 'Volume added. Redeploy the application to mount it.');
    }

    public function removeVolume(string $volumeUuid): void
    {
        try {
            $this->authorize('update', $this->workload);
            $outcome = RemoveNodeVolume::run($this->findVolume($volumeUuid));
            $this->dispatch('configurationChanged')->to(ConfigurationChecker::class);
            $this->dispatch('success', $outcome === 'deleted'
                ? 'Volume removed.'
                : 'Volume removed from the configuration. Redeploy the application to unmount it. Its data stays until you delete it.');
        } catch (\Throwable $exception) {
            handleError($exception, $this);
        }
    }

    public function deleteVolumeData(string $volumeUuid): void
    {
        try {
            $this->authorize('update', $this->workload);
            DeleteNodeVolumeData::run($this->findVolume($volumeUuid));
            $this->dispatch('success', 'Volume data deleted.');
        } catch (\Throwable $exception) {
            handleError($exception, $this);
        }
    }

    public function deleteVolumeCopy(string $copyUuid): void
    {
        try {
            $this->authorize('update', $this->workload);
            $copy = NodeVolumeCopy::query()
                ->where('uuid', $copyUuid)
                ->where('state', NodeVolumeCopyState::RETAINED)
                ->whereHas('volume', fn (Builder $volume) => $volume
                    ->where('node_workload_id', $this->workload->id)
                    ->where('team_id', $this->workload->team_id))
                ->firstOrFail();
            DeleteNodeVolumeCopy::run($copy);
            $this->dispatch('success', 'Retained copy deleted.');
        } catch (\Throwable $exception) {
            handleError($exception, $this);
        }
    }

    private function findVolume(string $volumeUuid): NodeVolume
    {
        return NodeVolume::query()
            ->where('node_workload_id', $this->workload->id)
            ->where('team_id', $this->workload->team_id)
            ->where('uuid', $volumeUuid)
            ->firstOrFail();
    }

    /**
     * @return array{volumes: list<array<string, mixed>>, retainedCopies: list<array<string, mixed>>, multipleServers: bool}
     */
    private function storageData(): array
    {
        $volumes = $this->workload->volumes()->with(['node', 'copies.node'])->orderBy('mount_path')->orderBy('id')->get();

        return [
            'volumes' => $volumes->map(fn (NodeVolume $volume): array => [
                'uuid' => $volume->uuid,
                'name' => $volume->name,
                'mountPath' => $volume->mount_path,
                'server' => $volume->node?->name,
                'detached' => $volume->isDetached(),
                'status' => $volume->inventoryStatus($volume->node?->freshVolumeInventory()),
                'receiving' => $volume->copies->contains('state', NodeVolumeCopyState::RECEIVING),
            ])->values()->all(),
            'retainedCopies' => $volumes->flatMap(fn (NodeVolume $volume) => $volume->copies
                ->where('state', NodeVolumeCopyState::RETAINED)
                ->map(fn (NodeVolumeCopy $copy): array => [
                    'uuid' => $copy->uuid,
                    'volume' => $volume->name,
                    'mountPath' => $volume->mount_path,
                    'server' => $copy->node?->name,
                    'deleteAfter' => $copy->delete_after,
                ]))->values()->all(),
            'multipleServers' => $this->workload->nodes->count() > 1,
        ];
    }

    public function saveDetails(): void
    {
        $this->authorize('update', $this->workload);
        $validated = $this->validate([
            'name' => ValidationPatterns::nameRules(),
            'description' => ValidationPatterns::descriptionRules(),
        ], ValidationPatterns::combinedMessages());

        // The internal DNS name is allocated once, so renaming keeps the internal hostname stable.
        $this->workload->update([
            'name' => trim($validated['name']),
            'description' => filled($validated['description']) ? trim($validated['description']) : null,
        ]);
        $this->loadDetails();
        $this->dispatch('success', 'Application updated.');
    }

    /**
     * Saves the General page of a database, like the v4 database General page. Credentials are
     * environment variables, so the container gets new values at the next deployment.
     */
    public function saveDatabase(): void
    {
        $this->authorize('update', $this->workload);
        $type = $this->workload->database_type;
        abort_if($type === null, 404);
        $credentialRules = collect($type->credentials())
            ->reject(fn (array $credential): bool => in_array($credential['key'], $this->lockedDatabaseCredentials, true))
            ->mapWithKeys(fn (array $credential): array => ['databaseCredentials.'.$credential['key'] => ['required', 'string', 'max:255', 'not_regex:/\x00/']])
            ->all();
        $validated = $this->validate([
            'name' => ValidationPatterns::nameRules(),
            'description' => ValidationPatterns::descriptionRules(),
            'databaseImage' => ['required', 'string', 'max:255', new DockerImageFormat],
            ...$credentialRules,
        ], ValidationPatterns::combinedMessages());

        $this->workload->update([
            'name' => trim($validated['name']),
            'description' => filled($validated['description']) ? trim($validated['description']) : null,
        ]);
        foreach ($type->credentials() as $credential) {
            if (array_key_exists('databaseCredentials.'.$credential['key'], $credentialRules)) {
                CreateClusterDatabaseWorkload::storeCredential($this->workload, $credential, $this->databaseCredentials[$credential['key']]);
            }
        }
        $revision = $this->workload->revisions()->latest('id')->first();
        $image = CreateClusterDockerImageWorkload::qualifiedImage(trim($validated['databaseImage']));
        if ($revision !== null && $image !== $revision->image) {
            UpdateNodeWorkloadConfiguration::run($this->workload, $revision->configuration['command'] ?? [], null, $image);
        }
        $this->loadData();
        $this->loadDetails();
        $this->loadDatabase();
        $this->dispatch('configurationChanged')->to(ConfigurationChecker::class);
        $this->dispatch('success', 'Database updated. Redeploy the database to apply the changes.');
    }

    public function restart(): void
    {
        $this->queueLifecycle(NodeWorkloadAction::RESTART);
    }

    public function stop(): void
    {
        $this->queueLifecycle(NodeWorkloadAction::STOP);
    }

    /** Removes the exited container on every server. The application stays placed on its servers. */
    public function removeContainer(): void
    {
        $this->queueLifecycle(NodeWorkloadAction::REMOVE);
    }

    /** Moves the application from one of its servers to another server of the same cluster. */
    public function moveWorkload(): void
    {
        try {
            $this->authorize('update', $this->workload);
            $this->validate([
                'moveSource' => ['required', 'string'],
                'moveTarget' => ['required', 'string'],
            ]);
            $source = $this->workload->nodes()->where('nodes.uuid', $this->moveSource)->firstOrFail();
            $target = Node::query()
                ->where('uuid', $this->moveTarget)
                ->where('team_id', $this->workload->team_id)
                ->where('node_cluster_id', $source->node_cluster_id)
                ->whereDoesntHave('workloads', fn ($query) => $query->whereKey($this->workload->id))
                ->firstOrFail();
            $revision = $this->workload->revisions()->latest('id')->firstOrFail();
            $recovery = NotifyNodeReachability::isOffline($source);
            $hasVolumes = NodeVolume::query()->where('node_workload_id', $this->workload->id)->whereNotNull('node_id')->exists();
            if ($recovery && $hasVolumes && ! $this->moveRecoveryConfirmed) {
                $this->addError('moveRecoveryConfirmed', 'Confirm that the volumes are restored from their latest backups.');

                return;
            }
            $operation = CreateMoveOperation::run($source, $target, $revision, auth()->user(), recovery: $recovery);
            MoveNodeWorkloadJob::dispatch($operation->id);
            $this->moveTarget = '';
            $this->moveRecoveryConfirmed = false;
            $this->dispatch('close-modal');
            $this->dispatch('success', $recovery
                ? 'Recovery move queued. The application starts on '.$target->name.($hasVolumes ? ' with the volumes from their latest backups' : '').'. Its old container on '.$source->name.' is removed when that server comes back.'
                : (data_get($operation->result, 'stateful') || $hasVolumes
                ? 'Move queued. Coolify copies the volumes first; the application is stopped only for the final copy.'
                : 'Move queued. The application keeps running on '.$source->name.' until it is ready on '.$target->name.'.'));
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            handleError($exception, $this);
        }
        $this->loadData();
    }

    /**
     * Servers the application can move from and to. Targets are servers of the same cluster that run workloads
     * and do not run this application yet.
     *
     * @return array{moveSourceOptions: list<array{value: string, label: string}>, moveTargetOptions: list<array{value: string, label: string}>}
     */
    private function moveOptions(): array
    {
        $sources = $this->workload->nodes->sortBy('name')->values();
        $clusterIds = $sources->pluck('node_cluster_id')->filter()->unique()->values();
        $targets = $clusterIds->isEmpty() ? collect() : Node::query()
            ->where('team_id', $this->workload->team_id)
            ->whereIn('node_cluster_id', $clusterIds)
            ->whereKeyNot($sources->modelKeys())
            ->orderBy('name')
            ->get()
            ->filter(fn (Node $node): bool => $node->role->runsWorkloads());

        return [
            'moveSourceOptions' => $sources->map(fn (Node $node): array => ['value' => $node->uuid, 'label' => $node->name])->all(),
            'moveTargetOptions' => $targets->map(fn (Node $node): array => ['value' => $node->uuid, 'label' => $node->name])->values()->all(),
            'moveRecovery' => $this->moveRecovery($sources->firstWhere('uuid', $this->moveSource), $targets->firstWhere('uuid', $this->moveTarget) ?? $targets->first()),
        ];
    }

    /**
     * What a move from an offline server restores (decision 0014): the backup of each volume, or
     * the reason why the move is blocked. Null while the source is online.
     *
     * @return array{source: string, backups: list<array{volume: string, finished_at: ?string, size: ?string}>, error: ?string}|null
     */
    private function moveRecovery(?Node $source, ?Node $target): ?array
    {
        if ($source === null || $target === null || ! NotifyNodeReachability::isOffline($source)) {
            return null;
        }
        try {
            $backups = collect(CreateMoveOperation::recoveryBackups($this->workload, $source, $target));
            $volumes = $this->workload->volumes()->whereIn('uuid', $backups->keys())->pluck('name', 'uuid');
            $error = null;
        } catch (\RuntimeException $exception) {
            $backups = collect();
            $error = $exception->getMessage();
        }

        return [
            'source' => $source->name,
            'backups' => $backups->map(fn ($execution, string $uuid): array => [
                'volume' => (string) ($volumes[$uuid] ?? $uuid),
                'finished_at' => ($execution->finished_at ?? $execution->created_at)?->copy()->setTimezone(instanceSettings()->instance_timezone ?: config('app.timezone'))->format('Y-m-d H:i T'),
                'size' => $execution->size ? formatBytes((int) $execution->size) : null,
            ])->values()->all(),
            'error' => $error,
        ];
    }

    /**
     * The deployment log of an active deployment, for the "Deploying… View log" indicator.
     */
    public function getRunningDeploymentUrlProperty(): ?string
    {
        $uuid = $this->deploymentQuery()
            ->whereIn('status', [
                NodeOperationStatus::QUEUED,
                NodeOperationStatus::DISPATCHED,
                NodeOperationStatus::RUNNING,
                NodeOperationStatus::VERIFYING,
            ])
            ->latest('id')
            ->value('uuid');

        return $uuid === null ? null : route('project.cluster-application.deployment.show', [
            'project_uuid' => $this->project->uuid,
            'environment_uuid' => $this->environment->uuid,
            'workload_uuid' => $this->workload->uuid,
            'deployment_uuid' => $uuid,
        ]);
    }

    public function updatedDeploymentSearch(): void
    {
        $this->deploymentPage = 1;
    }

    public function toggleDeploymentFilter(string $filter): void
    {
        $this->deploymentFilters = in_array($filter, $this->deploymentFilters, true)
            ? array_values(array_diff($this->deploymentFilters, [$filter]))
            : [...$this->deploymentFilters, $filter];
        $this->deploymentPage = 1;
    }

    public function clearDeploymentFilters(): void
    {
        $this->deploymentFilters = [];
        $this->deploymentPage = 1;
    }

    public function setDeploymentSort(string $sort): void
    {
        $this->deploymentSort = $sort === 'oldest' ? 'oldest' : 'newest';
        $this->deploymentPage = 1;
    }

    /** @return array{status: list<array{value: string, label: string}>, server: list<array{value: string, label: string}>} */
    private function deploymentFilterOptions(): array
    {
        return [
            'status' => [
                ['value' => 'status:success', 'label' => 'Success'],
                ['value' => 'status:failed', 'label' => 'Failed'],
                ['value' => 'status:active', 'label' => 'In progress'],
                ['value' => 'status:other', 'label' => 'Cancelled or uncertain'],
            ],
            'server' => $this->workload->nodes
                ->map(fn (Node $node): array => ['value' => 'server:'.$node->uuid, 'label' => $node->name])
                ->values()
                ->all(),
        ];
    }

    /**
     * Shows the log of a deployment, or of a build that deployed nothing, without a
     * page load, like the v4 deployment page.
     */
    public function selectDeployment(string $deploymentUuid): void
    {
        $routeParameters = [
            'project_uuid' => $this->project->uuid,
            'environment_uuid' => $this->environment->uuid,
            'workload_uuid' => $this->workload->uuid,
        ];
        $uuid = $this->deploymentQuery()->where('uuid', $deploymentUuid)->value('uuid');
        if ($uuid !== null) {
            $this->section = 'deployment';
            $this->deploymentUuid = $uuid;
            $this->buildUuid = null;
            $url = route('project.cluster-application.deployment.show', [...$routeParameters, 'deployment_uuid' => $uuid]);
        } else {
            $uuid = $this->buildTimelineQuery()->where('uuid', $deploymentUuid)->value('uuid');
            if ($uuid === null) {
                return;
            }
            $this->section = 'build';
            $this->buildUuid = $uuid;
            $this->deploymentUuid = null;
            $url = route('project.cluster-application.build.show', [...$routeParameters, 'build_uuid' => $uuid]);
        }
        $this->js('history.replaceState(history.state, "", '.json_encode($url).')');
    }

    public function goToPage(int $page): void
    {
        $this->deploymentPage = max(1, $page);
    }

    public function previousPage(): void
    {
        $this->goToPage($this->deploymentPage - 1);
    }

    public function nextPage(): void
    {
        $this->goToPage($this->deploymentPage + 1);
    }

    private function queueLifecycle(NodeWorkloadAction $action): void
    {
        try {
            $this->authorize('update', $this->workload);
            if (! $this->ensureHasServer()) {
                return;
            }
            $revision = $this->workload->revisions()->latest('id')->firstOrFail();
            $failures = [];
            $queued = 0;
            foreach ($this->workload->nodes()->orderBy('nodes.id')->get() as $node) {
                try {
                    $operation = CreateLifecycleOperation::run($node, $revision, $action, auth()->user());
                    ManageNodeWorkloadJob::dispatch($operation->id);
                    $queued++;
                } catch (\RuntimeException $exception) {
                    $failures[] = "{$node->name}: {$exception->getMessage()}";
                }
            }
            if ($queued > 0) {
                match ($action) {
                    NodeWorkloadAction::STOP => $this->dispatch('info', 'Gracefully stopping application.<br/>It could take a while depending on the application.'),
                    NodeWorkloadAction::REMOVE => $this->dispatch('info', 'Removing the application container.'),
                    default => $this->dispatch('success', str($action->value)->title().' command queued.'),
                };
            }
            if ($failures !== []) {
                $this->dispatch('error', 'Failed to '.$action->value.' the application on every server.', implode('<br>', array_map('e', $failures)));
            }
        } catch (\Throwable $exception) {
            handleError($exception, $this);
        }
        $this->loadData();
    }

    /** Shows an error toast and returns false when no server runs the application. */
    private function ensureHasServer(): bool
    {
        if ($this->workload->nodes()->exists()) {
            return true;
        }
        $this->dispatch('error', 'This application is not placed on any server.', 'Delete it from the Danger Zone and create it again on a cluster.');
        $this->loadData();

        return false;
    }

    public function render(): View
    {
        return view('livewire.project.cluster-application.show', [
            'image' => $this->workload->revisions->first()?->image,
            'internalHostname' => $this->workload->internal_dns_name
                ? $this->workload->internal_dns_name.'.default.coolify.internal'
                : null,
            'databaseUrl' => $this->section === 'general' ? $this->databaseInternalUrl($this->workload->internal_dns_name ? $this->workload->internal_dns_name.'.default.coolify.internal' : null) : null,
            ...($this->section === 'domains' ? $this->ingressData() : []),
            ...($this->section === 'storage' ? $this->storageData() : []),
            ...$this->moveOptions(),
            'servers' => $this->workload->nodes->sortBy('name')->values(),
            'registryCredentialOptions' => $this->section === 'general' ? $this->registryCredentialOptions() : [],
            'deploymentHistory' => in_array($this->section, ['deployments', 'deployment', 'build'], true) ? $this->deploymentHistory() : null,
            'deploymentFilterOptions' => in_array($this->section, ['deployments', 'deployment', 'build'], true) ? $this->deploymentFilterOptions() : null,
            'selectedDeployment' => in_array($this->section, ['deployment', 'build'], true) ? $this->selectedDeployment() : null,
            'routeParameters' => [
                'project_uuid' => $this->project->uuid,
                'environment_uuid' => $this->environment->uuid,
                'workload_uuid' => $this->workload->uuid,
            ],
        ]);
    }

    /** @return array{ingressAddresses: list<string>, ingressCluster: ?NodeCluster, ingressTls: bool, ingressRoutesV2: bool, domainCertificates: array<string, array<string, mixed>>, suggestedDomain: ?string} */
    private function ingressData(): array
    {
        $ingressAddresses = $this->ingressAddresses();

        return [
            'ingressAddresses' => $ingressAddresses,
            'ingressCluster' => $this->node?->cluster,
            'ingressTls' => (bool) $this->node?->cluster?->supportsIngressTls(),
            'ingressRoutesV2' => $this->node?->cluster === null || $this->node->cluster->supportsIngressRoutesV2(),
            'domainCertificates' => $this->workload->domainCertificates(),
            'suggestedDomain' => $this->suggestedDomain($ingressAddresses),
        ];
    }

    /** @return list<string> */
    private function ingressAddresses(): array
    {
        $cluster = $this->node?->cluster;

        return $cluster === null ? [] : Node::query()
            ->where('team_id', $this->workload->team_id)
            ->where('node_cluster_id', $cluster->id)
            ->where('is_ingress', true)
            ->orderBy('name')
            ->pluck('ip')
            ->filter()
            ->values()
            ->all();
    }

    /**
     * An sslip.io domain that points to the first ingress server, like the generated domains of v4.
     * In development, the ingress servers are VMs on a private network, so it uses DEV_SEED_IP, like the seeded v4 domains.
     *
     * @param  list<string>  $ingressAddresses
     */
    private function suggestedDomain(array $ingressAddresses): ?string
    {
        $ingress = $this->node?->cluster === null ? null : Node::query()
            ->where('team_id', $this->workload->team_id)
            ->where('node_cluster_id', $this->node->node_cluster_id)
            ->where('is_ingress', true)
            ->orderBy('name')
            ->first();
        $wildcard = data_get($ingress?->settings, 'wildcard_domain');
        if (filled($wildcard)) {
            return $this->workload->uuid.'.'.parse_url($wildcard, PHP_URL_HOST);
        }

        $ip = isDev() ? config('constants.coolify.dev_seed_ip') : ($ingressAddresses[0] ?? null);
        if ($ip === null || filter_var(normalizeIpAddress($ip), FILTER_VALIDATE_IP) === false) {
            return null;
        }

        return $this->workload->uuid.'.'.sslipHostLabel($ip).'.sslip.io';
    }

    /**
     * One timeline of deployments and builds. A deployment of a built image shows the
     * commit of its build; a build is a row of its own only while no deployment uses
     * its image (it failed, was cancelled, still runs, or had no server).
     *
     * @return array{rows: list<array<string, mixed>>, total: int, currentPage: int, lastPage: int, from: int, to: int}
     */
    private function deploymentHistory(): array
    {
        $deployments = $this->deploymentQuery()->toBase()->select(['id', 'created_at'])->selectRaw("'deployment' as kind");
        $builds = $this->buildTimelineQuery()->toBase()->select(['id', 'created_at'])->selectRaw("'build' as kind");
        $timeline = fn () => $deployments->newQuery()->fromSub($deployments->clone()->unionAll($builds), 'timeline');

        $total = $timeline()->count();
        $lastPage = max(1, (int) ceil($total / self::DEPLOYMENTS_PER_PAGE));
        $this->deploymentPage = min(max(1, $this->deploymentPage), $lastPage);
        $skip = ($this->deploymentPage - 1) * self::DEPLOYMENTS_PER_PAGE;
        $direction = $this->deploymentSort === 'oldest' ? 'asc' : 'desc';
        $page = $timeline()
            ->orderBy('created_at', $direction)
            ->orderBy('kind', $direction)
            ->orderBy('id', $direction)
            ->skip($skip)
            ->take(self::DEPLOYMENTS_PER_PAGE)
            ->get();
        $operations = NodeOperation::query()->with(['node', 'revision.build'])
            ->findMany($page->where('kind', 'deployment')->pluck('id'))->keyBy('id');
        $buildRows = NodeBuild::query()
            ->findMany($page->where('kind', 'build')->pluck('id'))->keyBy('id');
        $rows = $page
            ->map(fn (object $row): array => $row->kind === 'build'
                ? $this->buildTimelineRow($buildRows[$row->id])
                : $this->deploymentTimelineRow($operations[$row->id]))
            ->values()
            ->all();

        return [
            'rows' => $rows,
            'total' => $total,
            'currentPage' => $this->deploymentPage,
            'lastPage' => $lastPage,
            'from' => $total === 0 ? 0 : $skip + 1,
            'to' => min($skip + count($rows), $total),
        ];
    }

    /**
     * The deploy that the log viewer shows: a deployment with the build that made its
     * image, or a build that has deployed nothing (yet). On the build page of a build
     * that deployed, the view follows its deployment.
     *
     * @return array{uuid: string, status: string, statusType: string, isActive: bool, duration: ?string, build: ?array<string, mixed>, deployNote: ?string, lines: list<array{timestamp: string, line: string, stderr: bool}>}
     */
    private function selectedDeployment(): array
    {
        if ($this->section === 'build') {
            $build = $this->workload->builds()->where('uuid', $this->buildUuid)->firstOrFail();
            $operation = $build->node_workload_revision_id === null ? null : NodeOperation::query()
                ->with(['node', 'revision', 'requestedBy'])
                ->where('node_workload_id', $this->workload->id)
                ->whereIn('command_type', self::DEPLOYMENT_COMMAND_TYPES)
                ->where('node_workload_revision_id', $build->node_workload_revision_id)
                ->latest('id')
                ->first();
        } else {
            $operation = $this->deploymentQuery()
                ->with(['node', 'revision.build', 'requestedBy'])
                ->where('uuid', $this->deploymentUuid)
                ->firstOrFail();
            $build = $operation->revision?->build;
        }

        return [
            'uuid' => $operation?->uuid ?? $build->uuid,
            'build' => $build ? [
                'status' => $build->status->deploymentLabel(),
                'statusType' => $build->status->badgeType(),
                'isActive' => ! $build->status->isFinal(),
                // Open while it is the interesting part: the build page, or a build that deployed nothing.
                'open' => $this->section === 'build' || $operation === null,
                'duration' => $build->started_at && $build->finished_at ? calculateDuration($build->started_at, $build->finished_at) : null,
                'lines' => $build->logLines()->orderBy('id')->get()
                    ->map(fn (NodeBuildLogLine $line): array => [
                        'timestamp' => $line->logged_at->format('Y-M-d H:i:s'),
                        'line' => $line->line,
                        'stderr' => false,
                    ])->all(),
            ] : null,
            'status' => $operation?->status->label() ?? $build->status->deploymentLabel(),
            'statusType' => $operation?->status->badgeType() ?? $build->status->badgeType(),
            'isActive' => $operation ? $operation->status->isActive() : ! $build->status->isFinal(),
            'duration' => match (true) {
                $operation === null => null,
                $operation->completed_at !== null => calculateDuration($operation->created_at, $operation->completed_at),
                $operation->status->isActive() => calculateDuration($operation->created_at, now()),
                default => null,
            },
            'deployNote' => $operation ? null : match ($build->status) {
                NodeBuildStatus::QUEUED, NodeBuildStatus::RUNNING => 'The deploy starts when the build succeeds.',
                NodeBuildStatus::FAILED => 'Nothing was deployed because the build failed.',
                NodeBuildStatus::CANCELLED => 'Nothing was deployed because the build was cancelled.',
                NodeBuildStatus::SUCCEEDED => 'Nothing was deployed. The application has no server.',
            },
            'lines' => $operation ? $this->deploymentLogLines($operation) : [],
        ];
    }

    /**
     * Operations do not store raw command output, so the log is built from the
     * recorded lifecycle timestamps, the Sentinel verification, and the error.
     *
     * @return list<array{timestamp: string, line: string, stderr: bool}>
     */
    private function deploymentLogLines(NodeOperation $operation): array
    {
        $lines = [];
        $add = function ($at, string $line, bool $stderr = false) use (&$lines): void {
            $lines[] = ['timestamp' => Carbon::parse($at)->format('Y-M-d H:i:s'), 'line' => $line, 'stderr' => $stderr];
        };
        $nodeName = $operation->node?->name ?? 'the server';
        $requestedBy = $operation->requestedBy?->name ?: $operation->requestedBy?->email;

        if ($operation->command_type === 'workload.move.v1') {
            $targetName = Node::query()->where('team_id', $this->workload->team_id)
                ->where('uuid', data_get($operation->request, 'target_node_uuid'))->value('name');
            $add($operation->created_at, (data_get($operation->request, 'restore') !== null ? 'Restore of a volume backup on ' : 'Move to ')
                .($targetName ?? 'another server').' queued'.($requestedBy ? " by {$requestedBy}." : '.'));
        } else {
            $add($operation->created_at, 'Deployment queued'.($requestedBy ? " by {$requestedBy}." : '.'));
        }
        if ($operation->revision) {
            $add($operation->created_at, "Revision {$operation->revision->uuid} uses image {$operation->revision->image}.");
            if ($build = $operation->revision->build) {
                $add($operation->created_at, $build->source_type->usesGit()
                    ? 'The image was built from '.($build->commit_sha ? "commit {$build->commit_sha} of " : '')."branch {$build->git_branch}."
                    : 'The image was built from the Dockerfile of the application.');
            }
        }
        $pullPolicy = data_get($operation->request, 'pull_policy');
        if ($pullPolicy) {
            $add($operation->created_at, $pullPolicy === 'newer' ? 'Sentinel pulls the image when a newer version is available.' : 'Sentinel pulls the image only when it is missing.');
        }
        if ($operation->dispatched_at) {
            $attempt = $operation->attempt_count > 1 ? " (attempt {$operation->attempt_count})" : '';
            $add($operation->dispatched_at, "Command sent to Sentinel on {$nodeName}{$attempt}.");
        }
        if ($operation->started_at) {
            $add($operation->started_at, 'Sentinel started the deployment.');
        }
        if ($operation->command_type === 'workload.move.v1' && data_get($operation->result, 'stateful')) {
            // Moves before per-pass progress stored only the last pass under `transfer`.
            $legacy = data_get($operation->result, 'transfer');
            $transfers = data_get($operation->result, 'transfers')
                ?? (is_array($legacy) ? [data_get($legacy, 'pass', 'warm') => $legacy] : []);
            foreach (['warm', 'final', 'verify'] as $pass) {
                $transfer = $transfers[$pass] ?? null;
                if (! is_array($transfer)) {
                    continue;
                }
                if ($pass === 'verify') {
                    $add($operation->updated_at, match ((string) data_get($transfer, 'state')) {
                        'succeeded' => 'Volume check: every file matches the source.',
                        'failed' => 'Volume check: the copy does not match the source.',
                        default => 'Volume check: comparing every file with the source.',
                    });

                    continue;
                }
                $done = (int) data_get($transfer, 'bytes_done', 0);
                $state = (string) data_get($transfer, 'state', 'running');
                $add($operation->updated_at, match (true) {
                    $state === 'succeeded' && $done === 0 => "Volume copy, {$pass} pass: no changes to copy.",
                    $state === 'succeeded' => "Volume copy, {$pass} pass: ".formatBytes($done).' copied.',
                    default => "Volume copy, {$pass} pass: ".formatBytes($done).' of '.formatBytes((int) data_get($transfer, 'bytes_total', 0))." ({$state}).",
                });
            }
            if (data_get($operation->result, 'source_stopped')) {
                $add($operation->updated_at, 'The source container was stopped for the final copy.');
            }
            if (data_get($operation->result, 'switched')) {
                $add($operation->updated_at, data_get($operation->result, 'switched_back')
                    ? 'The volumes were switched back to the source server.'
                    : 'The volumes were switched to the target server.');
            }
        }
        $verification = data_get($operation->result, 'verification');
        if (is_array($verification)) {
            $containerName = data_get($operation->result, 'name', 'The container');
            $state = data_get($verification, 'state', 'unknown');
            $add(
                data_get($verification, 'observed_at') ?? $operation->completed_at ?? $operation->updated_at,
                data_get($verification, 'converged')
                    ? "Container {$containerName} is {$state}."
                    : "Container {$containerName} did not reach the desired state. Current state: {$state}.",
                ! data_get($verification, 'converged'),
            );
        }

        $finishedAt = $operation->completed_at ?? $operation->updated_at;
        match ($operation->status) {
            NodeOperationStatus::SUCCEEDED => $add($finishedAt, 'Deployment finished successfully.'),
            NodeOperationStatus::FAILED => $add($finishedAt, 'Deployment failed.', true),
            NodeOperationStatus::TIMED_OUT => $add($finishedAt, 'Sentinel did not report a result in time.', true),
            NodeOperationStatus::UNCERTAIN => $add($finishedAt, 'The result is uncertain. Recover the operation from the Activity page of the server.', true),
            NodeOperationStatus::CANCELLED => $add($finishedAt, 'Deployment cancelled.'),
            default => null,
        };
        foreach (preg_split('/\r\n|\r|\n/', trim((string) $operation->error)) as $errorLine) {
            if ($errorLine !== '') {
                $add($finishedAt, $errorLine, true);
            }
        }

        return $lines;
    }

    /** @return array<string, mixed> */
    private function deploymentTimelineRow(NodeOperation $operation): array
    {
        $build = $operation->revision?->build;

        return [
            'kind' => 'deployment',
            'uuid' => $operation->uuid,
            'href' => route('project.cluster-application.deployment.show', [
                'project_uuid' => $this->project->uuid,
                'environment_uuid' => $this->environment->uuid,
                'workload_uuid' => $this->workload->uuid,
                'deployment_uuid' => $operation->uuid,
            ]),
            'status' => $operation->status->label(),
            'statusType' => $operation->status->badgeType(),
            'source' => match (true) {
                $operation->command_type === 'workload.move.v1' => 'Move',
                $operation->requested_by_id !== null => 'Manual',
                default => 'Automatic',
            },
            'revision' => $build?->commit_sha
                ? substr($build->commit_sha, 0, 8)
                : ($operation->revision ? substr($operation->revision->uuid, 0, 7) : null),
            'image' => $build ? $build->sourceLabel() : $operation->revision?->image,
            'imageTitle' => $operation->revision?->image,
            'createdAt' => $operation->created_at,
            'duration' => match ($operation->status) {
                NodeOperationStatus::QUEUED, NodeOperationStatus::DISPATCHED => 'Waiting',
                NodeOperationStatus::RUNNING, NodeOperationStatus::VERIFYING => calculateDuration($operation->created_at, now()),
                default => $operation->completed_at ? calculateDuration($operation->created_at, $operation->completed_at) : '-',
            },
            'node' => $operation->node?->name,
        ];
    }

    /** @return array<string, mixed> */
    private function buildTimelineRow(NodeBuild $build): array
    {
        return [
            'kind' => 'build',
            'uuid' => $build->uuid,
            'href' => route('project.cluster-application.build.show', [
                'project_uuid' => $this->project->uuid,
                'environment_uuid' => $this->environment->uuid,
                'workload_uuid' => $this->workload->uuid,
                'build_uuid' => $build->uuid,
            ]),
            'status' => $build->status->deploymentLabel(),
            'statusType' => $build->status->badgeType(),
            'source' => $build->requested_by_user_id !== null ? 'Manual' : 'Automatic',
            'revision' => $build->commit_sha ? substr($build->commit_sha, 0, 8) : null,
            'image' => $build->sourceLabel(),
            'imageTitle' => $build->git_repository,
            'createdAt' => $build->created_at,
            'duration' => match (true) {
                $build->started_at === null => $build->status->isFinal() ? '-' : 'Waiting',
                ! $build->status->isFinal() => calculateDuration($build->started_at, now()),
                default => $build->finished_at ? calculateDuration($build->started_at, $build->finished_at) : '-',
            },
            'node' => null,
        ];
    }

    /**
     * The builds that no deployment of this application uses. A build that a deployment
     * uses is shown in the row of that deployment.
     *
     * @return Builder<NodeBuild>
     */
    private function buildTimelineQuery(): Builder
    {
        $statuses = collect($this->deploymentFilters)
            ->filter(fn (string $filter): bool => str_starts_with($filter, 'status:'))
            ->flatMap(fn (string $filter): array => match (substr($filter, 7)) {
                'success' => [NodeBuildStatus::SUCCEEDED],
                'failed' => [NodeBuildStatus::FAILED],
                'active' => [NodeBuildStatus::QUEUED, NodeBuildStatus::RUNNING],
                'other' => [NodeBuildStatus::CANCELLED],
                default => [],
            })
            ->map(fn (NodeBuildStatus $status): string => $status->value)
            ->unique()
            ->values();
        $serverUuids = collect($this->deploymentFilters)
            ->filter(fn (string $filter): bool => str_starts_with($filter, 'server:'))
            ->map(fn (string $filter): string => substr($filter, 7))
            ->values();
        $search = trim($this->deploymentSearch);

        return NodeBuild::query()
            ->where('node_workload_id', $this->workload->id)
            ->whereDoesntHave('revision.operations', fn (Builder $operation) => $operation->whereIn('command_type', self::DEPLOYMENT_COMMAND_TYPES))
            ->when($statuses->isNotEmpty(), fn (Builder $query) => $query->whereIn('status', $statuses->all()))
            // These builds deployed to no server, so a server filter hides them.
            ->when($serverUuids->isNotEmpty(), fn (Builder $query) => $query->whereRaw('1 = 0'))
            ->when($search !== '', fn (Builder $query) => $query->where(fn (Builder $match) => $match
                ->where('uuid', 'like', $search.'%')
                ->orWhere('commit_sha', 'like', $search.'%')
                ->orWhere('git_branch', 'like', '%'.$search.'%')));
    }

    private function deploymentQuery(): Builder
    {
        $statuses = collect($this->deploymentFilters)
            ->filter(fn (string $filter): bool => str_starts_with($filter, 'status:'))
            ->flatMap(fn (string $filter): array => match (substr($filter, 7)) {
                'success' => [NodeOperationStatus::SUCCEEDED],
                'failed' => [NodeOperationStatus::FAILED, NodeOperationStatus::TIMED_OUT],
                'active' => [NodeOperationStatus::QUEUED, NodeOperationStatus::DISPATCHED, NodeOperationStatus::RUNNING, NodeOperationStatus::VERIFYING],
                'other' => [NodeOperationStatus::CANCELLED, NodeOperationStatus::UNCERTAIN],
                default => [],
            })
            ->map(fn (NodeOperationStatus $status): string => $status->value)
            ->unique()
            ->values();
        $serverUuids = collect($this->deploymentFilters)
            ->filter(fn (string $filter): bool => str_starts_with($filter, 'server:'))
            ->map(fn (string $filter): string => substr($filter, 7))
            ->values();
        $search = trim($this->deploymentSearch);

        return NodeOperation::query()
            ->where('node_workload_id', $this->workload->id)
            ->whereIn('command_type', self::DEPLOYMENT_COMMAND_TYPES)
            ->when($statuses->isNotEmpty(), fn (Builder $query) => $query->whereIn('status', $statuses->all()))
            ->when($serverUuids->isNotEmpty(), fn (Builder $query) => $query->whereHas('node', fn (Builder $node) => $node->whereIn('uuid', $serverUuids->all())))
            ->when($search !== '', fn (Builder $query) => $query->where(fn (Builder $match) => $match
                ->where('uuid', 'like', $search.'%')
                ->orWhereHas('revision', fn (Builder $revision) => $revision
                    ->where('uuid', 'like', $search.'%')
                    ->orWhere('image', 'like', '%'.$search.'%')
                    ->orWhereHas('build', fn (Builder $build) => $build
                        ->where('commit_sha', 'like', $search.'%')
                        ->orWhere('git_branch', 'like', '%'.$search.'%')))
                ->orWhereHas('node', fn (Builder $node) => $node->where('name', 'like', '%'.$search.'%'))));
    }

    private function resolveSection(?string $routeName): string
    {
        if ($routeName === 'project.cluster-application.deployment.show') {
            return 'deployment';
        }
        if ($routeName === 'project.cluster-application.backup.show') {
            return 'backups';
        }
        if ($routeName === 'project.cluster-application.build.show') {
            return 'build';
        }
        $section = str($routeName ?? '')->after('project.cluster-application.')->toString();

        return in_array($section, self::SECTIONS, true) ? $section : 'general';
    }

    private function loadData(): void
    {
        $this->workload->load([
            'revisions' => fn ($query) => $query->latest('id')->limit(1),
            'nodes.cluster',
            'operations' => fn ($query) => $query->latest('id')->limit(20),
        ]);
        $this->node = $this->workload->nodes->first();
        if ($this->workload->nodes->where('uuid', $this->moveSource)->isEmpty()) {
            $this->moveSource = $this->node?->uuid ?? '';
        }
        // Worst state wins across servers, like the Resources page of a cluster.
        $states = $this->workload->nodes->map(fn (Node $node): NodeWorkloadState => DetermineWorkloadState::run($node, $this->workload));
        $state = $states->first(fn (NodeWorkloadState $state): bool => $state !== NodeWorkloadState::RUNNING)
            ?? $states->first()
            ?? NodeWorkloadState::UNKNOWN;
        $this->status = str($state->value)->title()->toString();
        $this->statusType = $state->badgeType();
        if ($state === NodeWorkloadState::RUNNING) {
            // Like v4: "Running (healthy)".
            $health = $this->workload->observedHealth();
            if ($health !== null) {
                $this->status .= " ({$health})";
                $this->statusType = $health === 'healthy' ? 'success' : 'warning';
            }
        }
        $this->containerPresent = NodeContainer::query()
            ->where('node_workload_id', $this->workload->id)
            ->whereIn('node_id', $this->workload->nodes->modelKeys())
            ->where('is_managed', true)
            ->exists();
        // A new application is created stopped and has no container until its first deployment, like v4 shows it.
        if (! $this->containerPresent && $this->workload->isExited()) {
            $this->status = 'Exited';
            $this->statusType = 'error';
        }
    }

    private function loadDatabase(): void
    {
        $type = $this->workload->database_type;
        if ($type === null) {
            return;
        }
        $this->databaseImage = (string) $this->workload->revisions()->latest('id')->value('image');
        $variables = $this->workload->environment_variables()->whereIn('key', array_column($type->credentials(), 'key'))->get()->keyBy('key');
        $this->lockedDatabaseCredentials = $variables->filter(fn ($variable): bool => (bool) $variable->is_shown_once)->keys()->values()->all();
        // Livewire sends public properties to the browser, so only users who can update the database get the values.
        $this->databaseCredentials = auth()->user()?->can('update', $this->workload)
            ? collect($type->credentials())
                ->mapWithKeys(fn (array $credential): array => [$credential['key'] => in_array($credential['key'], $this->lockedDatabaseCredentials, true) ? '' : (string) $variables->get($credential['key'])?->value])
                ->all()
            : [];
    }

    /** The internal connection URL of a database, or null when the user cannot see the credentials or one is locked. */
    private function databaseInternalUrl(?string $internalHostname): ?string
    {
        $type = $this->workload->database_type;
        if ($type === null || $internalHostname === null || $this->databaseCredentials === [] || $this->lockedDatabaseCredentials !== []) {
            return null;
        }
        $values = $this->workload->environment_variables()->whereIn('key', array_column($type->credentials(), 'key'))->get()
            ->mapWithKeys(fn ($variable): array => [$variable->key => (string) $variable->value])
            ->all();

        return $type->connectionUrl($values, $internalHostname);
    }

    private function loadDetails(): void
    {
        $this->name = $this->workload->name;
        $this->description = $this->workload->description;
    }

    /**
     * Saves the Git source of a built application. The next build uses it; the start command
     * goes into a new revision like for an image application.
     */
    private function saveSourceConfiguration(): void
    {
        $this->gitBranch = trim($this->gitBranch);
        $usesGit = $this->workload->buildsFromGit();
        $this->validate([
            ...($usesGit ? [
                'gitBranch' => ['required', 'string', 'max:255', new ValidGitBranch],
                'buildPack' => ['required', Rule::enum(NodeWorkloadSourceType::class)->only(NodeWorkloadSourceType::gitBuildPacks())],
            ] : [
                'dockerfile' => CreateClusterInlineDockerfileWorkload::dockerfileRules(),
            ]),
            'baseDirectory' => ['nullable', 'string', 'max:512', 'regex:/^\/?[A-Za-z0-9._\/-]*$/', 'not_regex:/(^|\/)\.\.(\/|$)/'],
            'dockerfileLocation' => ['nullable', 'string', 'max:512', 'regex:/^\/?[A-Za-z0-9._\/-]*$/', 'not_regex:/(^|\/)\.\.(\/|$)/'],
            'startCommand' => ['nullable', 'string', 'max:16384'],
            'dockerRegistryImageName' => [...ValidationPatterns::dockerImageNameRules(), 'not_regex:/@/', 'not_regex:/^(localhost|'.preg_quote(NodeRegistry::HOST, '/').')\//'],
            'dockerRegistryImageTag' => ['nullable', 'string', 'max:128', 'regex:'.ValidationPatterns::DOCKER_IMAGE_LITERAL_TAG_PATTERN],
        ], [
            'dockerRegistryImageName.regex' => 'Enter an image repository without a tag, for example ghcr.io/acme/api.',
            'dockerRegistryImageName.not_regex' => 'Enter an image repository outside the cluster registry, without a digest.',
        ]);
        $command = $this->parseStartCommand();
        $imageName = trim($this->dockerRegistryImageName);
        $credential = null;
        if ($imageName === '' && $this->registryCredentialUuid !== '') {
            $this->addError('registryCredentialUuid', 'Set the Docker image name to push with a registry credential.');
        } elseif ($imageName !== '') {
            $credential = $this->selectedRegistryCredential($imageName, 'registryCredentialUuid');
        }
        if ($this->getErrorBag()->isNotEmpty()) {
            return;
        }
        $this->workload->update([
            'registry_credential_id' => $credential?->id,
            'docker_registry_image_name' => $imageName === '' ? null : $imageName,
            'docker_registry_image_tag' => filled($this->dockerRegistryImageTag) ? trim($this->dockerRegistryImageTag) : null,
            ...($usesGit ? [
                'git_branch' => $this->gitBranch,
                'source_type' => NodeWorkloadSourceType::from($this->buildPack),
                'base_directory' => filled($this->baseDirectory) ? trim($this->baseDirectory, ' /') : null,
                'dockerfile_location' => filled($this->dockerfileLocation) ? ltrim(trim($this->dockerfileLocation), '/') : null,
            ] : ['dockerfile' => $this->dockerfile]),
        ]);
        $revision = $this->workload->revisions()->latest('id')->first();
        if ($revision !== null && $command !== ($revision->configuration['command'] ?? [])) {
            UpdateNodeWorkloadConfiguration::run($this->workload, $command, null, $revision->image);
        } elseif ($revision === null && $command !== []) {
            $this->addError('startCommand', 'Deploy the application once before you set a start command.');

            return;
        }
        $this->loadData();
        $this->loadConfiguration();
        $this->dispatch('configurationChanged')->to(ConfigurationChecker::class);
        $this->dispatch('success', 'Configuration saved. Deploy to build the new source.');
    }

    /**
     * The selected registry credential, which must belong to the team of the workload and be for
     * the registry of the image. Adds a validation error otherwise.
     */
    private function selectedRegistryCredential(string $image, string $errorField): ?RegistryCredential
    {
        if ($this->registryCredentialUuid === '') {
            return null;
        }
        $credential = RegistryCredential::query()
            ->where('team_id', $this->workload->team_id)
            ->where('uuid', $this->registryCredentialUuid)
            ->first();
        if ($credential === null) {
            $this->addError($errorField, 'Select a registry credential of this team.');

            return null;
        }
        if (! $credential->matchesImage($image)) {
            $this->addError($errorField, "This credential is for {$credential->registry}, but the image is in ".DockerRegistryLogins::registryFromImage($image).'.');

            return null;
        }

        return $credential;
    }

    /** @return list<array{value: string, label: string, description: string}> */
    private function registryCredentialOptions(): array
    {
        return RegistryCredential::query()
            ->where('team_id', $this->workload->team_id)
            ->orderBy('name')
            ->get()
            ->map(fn (RegistryCredential $credential): array => [
                'value' => $credential->uuid,
                'label' => $credential->name,
                'description' => $credential->registry.' · '.$credential->username,
            ])
            ->prepend(['value' => '', 'label' => 'None', 'description' => 'Public image, or a registry without a login'])
            ->values()
            ->all();
    }

    private function loadConfiguration(): void
    {
        $this->registryCredentialUuid = (string) $this->workload->registryCredential?->uuid;
        $this->dockerRegistryImageName = (string) $this->workload->docker_registry_image_name;
        $this->dockerRegistryImageTag = (string) $this->workload->docker_registry_image_tag;
        $this->gitBranch = (string) $this->workload->git_branch;
        $this->baseDirectory = (string) $this->workload->base_directory;
        $this->dockerfileLocation = (string) $this->workload->dockerfile_location;
        $this->dockerfile = (string) $this->workload->dockerfile;
        $this->buildPack = (string) $this->workload->source_type?->value;
        $revision = $this->workload->revisions->first();
        $configuration = $revision?->configuration ?? [];
        $image = (new DockerImageParser)->parse($revision?->image ?? '');
        $this->imageName = $image->getFullImageNameWithoutTag();
        $this->imageTag = $image->getTag();
        $this->startCommand = collect($configuration['command'] ?? [])
            ->map(fn (string $argument): string => preg_match('/[\s"]/', $argument) === 1 ? '"'.str_replace('"', '""', $argument).'"' : $argument)
            ->implode(' ');
    }

    /** @return list<string> */
    private function parseStartCommand(): array
    {
        $command = array_values(array_filter(
            str_getcsv(trim($this->startCommand), ' ', '"', ''),
            fn (?string $argument): bool => $argument !== null && $argument !== '',
        ));
        if (count($command) > 64 || collect($command)->contains(fn (string $argument): bool => mb_strlen($argument) > 4096)) {
            $this->addError('startCommand', 'Use at most 64 arguments with at most 4096 characters each.');

            return [];
        }

        return $command;
    }

    private function loadDomains(): void
    {
        $this->forceHttps = $this->workload->force_https;
        $this->permanentRedirect = $this->workload->permanent_redirect;
    }

    private function resetNewDomain(): void
    {
        $this->newDomainParts = [
            'scheme' => 'https',
            'host' => '',
            'port' => (string) ($this->workload->domain_routes[0]['port'] ?? 80),
            'path' => '',
        ];
    }

    private function loadResourceSettings(): void
    {
        $resources = $this->workload->revisions->first()?->configuration['resources'] ?? [];
        $this->cpuLimit = isset($resources['cpu_limit']) ? (string) $resources['cpu_limit'] : '';
        $this->cpuReservation = isset($resources['cpu_reservation']) ? (string) $resources['cpu_reservation'] : '';
        $this->memoryLimitMb = isset($resources['memory_limit_bytes']) ? (string) ((int) $resources['memory_limit_bytes'] / 1_048_576) : '';
        $this->memoryReservationMb = isset($resources['memory_reservation_bytes']) ? (string) ((int) $resources['memory_reservation_bytes'] / 1_048_576) : '';
    }
}
