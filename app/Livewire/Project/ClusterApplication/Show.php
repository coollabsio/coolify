<?php

namespace App\Livewire\Project\ClusterApplication;

use App\Actions\Node\CreateDeploymentOperation;
use App\Actions\Node\CreateLifecycleOperation;
use App\Actions\Node\DetermineWorkloadState;
use App\Actions\Node\PrepareNodeWorkloadRevision;
use App\Actions\Node\UpdateNodeWorkloadConfiguration;
use App\Actions\Node\UpdateNodeWorkloadDomains;
use App\Actions\Node\UpdateNodeWorkloadResources;
use App\Enums\NodeOperationStatus;
use App\Enums\NodeWorkloadAction;
use App\Enums\NodeWorkloadState;
use App\Jobs\DeployNodeWorkloadJob;
use App\Jobs\ManageNodeWorkloadJob;
use App\Livewire\Project\Shared\ConfigurationChecker;
use App\Models\Environment;
use App\Models\Node;
use App\Models\NodeCluster;
use App\Models\NodeContainer;
use App\Models\NodeOperation;
use App\Models\NodeWorkload;
use App\Models\Project;
use App\Support\ValidationPatterns;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

class Show extends Component
{
    use AuthorizesRequests;

    /** @var list<string> */
    public const SECTIONS = ['general', 'environment-variables', 'resource-limits', 'deployments', 'deployment', 'logs', 'danger'];

    /** @var list<string> */
    public const DEPLOYMENT_COMMAND_TYPES = NodeOperation::DEPLOYMENT_COMMAND_TYPES;

    public const DEPLOYMENTS_PER_PAGE = 10;

    #[Locked]
    public string $section = 'general';

    #[Locked]
    public ?string $deploymentUuid = null;

    public int $deploymentPage = 1;

    public Project $project;

    public Environment $environment;

    public NodeWorkload $workload;

    public Node $node;

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

    public string $startCommand = '';

    public string $domains = '';

    public string $httpPort = '';

    public function mount(string $project_uuid, string $environment_uuid, string $workload_uuid, ?string $deployment_uuid = null): void
    {
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
        $this->loadData();
        $this->loadDetails();
        $this->loadResourceSettings();
        $this->loadConfiguration();
        $this->loadDomains();
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
        $this->dispatch('success', 'Resource settings saved. Redeploy the application to apply them.');
    }

    public function saveConfiguration(): void
    {
        $this->authorize('update', $this->workload);
        $this->validate(['startCommand' => ['nullable', 'string', 'max:16384']]);
        $command = $this->parseStartCommand();
        if ($this->getErrorBag()->isNotEmpty()) {
            return;
        }

        UpdateNodeWorkloadConfiguration::run($this->workload, $command);
        $this->loadData();
        $this->loadConfiguration();
        $this->dispatch('configurationChanged')->to(ConfigurationChecker::class);
        $this->dispatch('success', 'Configuration saved. Redeploy the application to apply it.');
    }

    public function saveDomains(): void
    {
        $this->authorize('update', $this->workload);
        try {
            $this->workload = UpdateNodeWorkloadDomains::run($this->workload, $this->domains, $this->httpPort, auth()->user());
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $key => $messages) {
                $this->addError($key === 'http_port' ? 'httpPort' : 'domains', $messages[0]);
            }

            return;
        }
        $this->loadData();
        $this->loadDomains();
        $this->dispatch('success', 'Domains saved. Ingress servers apply them without a redeploy.');
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

    public function render(): View
    {
        return view('livewire.project.cluster-application.show', [
            'image' => $this->workload->revisions->first()?->image,
            'internalHostname' => $this->workload->internal_dns_name
                ? $this->workload->internal_dns_name.'.default.coolify.internal'
                : null,
            ...($this->section === 'general' ? $this->ingressData() : []),
            'servers' => $this->workload->nodes->sortBy('name')->values(),
            'deploymentHistory' => in_array($this->section, ['deployments', 'deployment'], true) ? $this->deploymentHistory() : null,
            'selectedDeployment' => $this->section === 'deployment' ? $this->selectedDeployment() : null,
            'routeParameters' => [
                'project_uuid' => $this->project->uuid,
                'environment_uuid' => $this->environment->uuid,
                'workload_uuid' => $this->workload->uuid,
            ],
        ]);
    }

    /** @return array{publicUrls: list<string>, ingressAddresses: list<string>, ingressCluster: ?NodeCluster} */
    private function ingressData(): array
    {
        $cluster = $this->node->cluster;

        return [
            'publicUrls' => collect($this->workload->domains ?? [])->map(fn (string $domain): string => 'http://'.$domain)->all(),
            'ingressAddresses' => $cluster === null ? [] : Node::query()
                ->where('team_id', $this->workload->team_id)
                ->where('node_cluster_id', $cluster->id)
                ->where('is_ingress', true)
                ->orderBy('name')
                ->pluck('ip')
                ->filter()
                ->values()
                ->all(),
            'ingressCluster' => $cluster,
        ];
    }

    /** @return array{rows: list<array<string, mixed>>, total: int, currentPage: int, lastPage: int, from: int, to: int} */
    private function deploymentHistory(): array
    {
        $total = $this->deploymentQuery()->count();
        $lastPage = max(1, (int) ceil($total / self::DEPLOYMENTS_PER_PAGE));
        $this->deploymentPage = min(max(1, $this->deploymentPage), $lastPage);
        $skip = ($this->deploymentPage - 1) * self::DEPLOYMENTS_PER_PAGE;
        $rows = $this->deploymentQuery()
            ->with(['node', 'revision'])
            ->latest('id')
            ->skip($skip)
            ->take(self::DEPLOYMENTS_PER_PAGE)
            ->get()
            ->map(fn (NodeOperation $operation): array => [
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
                'revision' => $operation->revision ? substr($operation->revision->uuid, 0, 7) : null,
                'image' => $operation->revision?->image,
                'createdAt' => $operation->created_at,
                'duration' => match ($operation->status) {
                    NodeOperationStatus::QUEUED, NodeOperationStatus::DISPATCHED => 'Waiting',
                    NodeOperationStatus::RUNNING, NodeOperationStatus::VERIFYING => calculateDuration($operation->created_at, now()),
                    default => $operation->completed_at ? calculateDuration($operation->created_at, $operation->completed_at) : '-',
                },
                'node' => $operation->node?->name,
            ])
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

    /** @return array{uuid: string, status: string, statusType: string, isActive: bool, lines: list<array{timestamp: string, line: string, stderr: bool}>} */
    private function selectedDeployment(): array
    {
        $operation = $this->deploymentQuery()
            ->with(['node', 'revision', 'requestedBy'])
            ->where('uuid', $this->deploymentUuid)
            ->firstOrFail();

        return [
            'uuid' => $operation->uuid,
            'status' => $operation->status->label(),
            'statusType' => $operation->status->badgeType(),
            'isActive' => $operation->status->isActive(),
            'lines' => $this->deploymentLogLines($operation),
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
            $add($operation->created_at, 'Move to '.($targetName ?? 'another server').' queued'.($requestedBy ? " by {$requestedBy}." : '.'));
        } else {
            $add($operation->created_at, 'Deployment queued'.($requestedBy ? " by {$requestedBy}." : '.'));
        }
        if ($operation->revision) {
            $add($operation->created_at, "Revision {$operation->revision->uuid} uses image {$operation->revision->image}.");
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

    private function deploymentQuery(): Builder
    {
        return NodeOperation::query()
            ->where('node_workload_id', $this->workload->id)
            ->whereIn('command_type', self::DEPLOYMENT_COMMAND_TYPES);
    }

    private function resolveSection(?string $routeName): string
    {
        if ($routeName === 'project.cluster-application.deployment.show') {
            return 'deployment';
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
        $this->node = $this->workload->nodes->firstOrFail();
        // Worst state wins across servers, like the Resources page of a cluster.
        $states = $this->workload->nodes->map(fn (Node $node): NodeWorkloadState => DetermineWorkloadState::run($node, $this->workload));
        $state = $states->first(fn (NodeWorkloadState $state): bool => $state !== NodeWorkloadState::RUNNING)
            ?? $states->first()
            ?? NodeWorkloadState::UNKNOWN;
        $this->status = str($state->value)->title()->toString();
        $this->statusType = $state->badgeType();
        $this->containerPresent = NodeContainer::query()
            ->where('node_workload_id', $this->workload->id)
            ->whereIn('node_id', $this->workload->nodes->modelKeys())
            ->where('is_managed', true)
            ->exists();
    }

    private function loadDetails(): void
    {
        $this->name = $this->workload->name;
        $this->description = $this->workload->description;
    }

    private function loadConfiguration(): void
    {
        $revision = $this->workload->revisions->first();
        $configuration = $revision?->configuration ?? [];
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
        $this->domains = implode("\n", $this->workload->domains ?? []);
        $this->httpPort = $this->workload->http_port === null ? '' : (string) $this->workload->http_port;
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
