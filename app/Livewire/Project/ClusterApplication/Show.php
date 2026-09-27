<?php

namespace App\Livewire\Project\ClusterApplication;

use App\Actions\Node\CreateDeploymentOperation;
use App\Actions\Node\CreateLifecycleOperation;
use App\Actions\Node\DetermineWorkloadState;
use App\Actions\Node\PrepareNodeWorkloadRevision;
use App\Actions\Node\UpdateNodeWorkloadConfiguration;
use App\Actions\Node\UpdateNodeWorkloadResources;
use App\Enums\NodeOperationStatus;
use App\Enums\NodeWorkloadAction;
use App\Jobs\DeployNodeWorkloadJob;
use App\Jobs\ManageNodeWorkloadJob;
use App\Livewire\Project\Shared\ConfigurationChecker;
use App\Models\Environment;
use App\Models\Node;
use App\Models\NodeOperation;
use App\Models\NodeWorkload;
use App\Models\Project;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Carbon;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

class Show extends Component
{
    use AuthorizesRequests;

    /** @var list<string> */
    public const SECTIONS = ['general', 'environment-variables', 'resource-limits', 'deployments', 'deployment'];

    /** @var list<string> */
    public const DEPLOYMENT_COMMAND_TYPES = ['workload.deploy.v1', 'workload.move.v1'];

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

    public string $cpuLimit = '';

    public string $cpuReservation = '';

    public string $memoryLimitMb = '';

    public string $memoryReservationMb = '';

    public string $startCommand = '';

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
        $this->loadResourceSettings();
        $this->loadConfiguration();
    }

    public function deploy(): void
    {
        try {
            $this->authorize('update', $this->workload);
            $revision = PrepareNodeWorkloadRevision::run($this->workload);
            $deployment = CreateDeploymentOperation::run($this->node, $revision, auth()->user(), 'newer');
        } catch (\Throwable $exception) {
            handleError($exception, $this);

            return;
        }
        $operation = $deployment['operation'];
        if ($deployment['created']) {
            DeployNodeWorkloadJob::dispatch($operation->id);
        } elseif (! in_array($operation->command_type, self::DEPLOYMENT_COMMAND_TYPES, true)) {
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

    public function manage(string $actionValue): void
    {
        try {
            $this->authorize('update', $this->workload);
            $action = NodeWorkloadAction::from($actionValue);
            if (! in_array($action, [NodeWorkloadAction::RESTART, NodeWorkloadAction::STOP], true)) {
                abort(404);
            }
            $revision = $this->workload->revisions()->latest('id')->firstOrFail();
            $operation = CreateLifecycleOperation::run($this->node, $revision, $action, auth()->user());
            ManageNodeWorkloadJob::dispatch($operation->id);
            $this->dispatch('success', str($action->value)->title().' command queued.');
            $this->loadData();
        } catch (\Throwable $exception) {
            handleError($exception, $this);
        }
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

    public function render(): View
    {
        return view('livewire.project.cluster-application.show', [
            'image' => $this->workload->revisions->first()?->image,
            'internalHostname' => $this->workload->internal_dns_name
                ? $this->workload->internal_dns_name.'.default.coolify.internal'
                : null,
            'deploymentHistory' => in_array($this->section, ['deployments', 'deployment'], true) ? $this->deploymentHistory() : null,
            'selectedDeployment' => $this->section === 'deployment' ? $this->selectedDeployment() : null,
            'routeParameters' => [
                'project_uuid' => $this->project->uuid,
                'environment_uuid' => $this->environment->uuid,
                'workload_uuid' => $this->workload->uuid,
            ],
        ]);
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
                'status' => $this->deploymentStatusLabel($operation->status),
                'statusType' => $this->deploymentStatusType($operation->status),
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
            'status' => $this->deploymentStatusLabel($operation->status),
            'statusType' => $this->deploymentStatusType($operation->status),
            'isActive' => in_array($operation->status, [
                NodeOperationStatus::QUEUED,
                NodeOperationStatus::DISPATCHED,
                NodeOperationStatus::RUNNING,
                NodeOperationStatus::VERIFYING,
            ], true),
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
        $nodeName = $operation->node?->name ?? 'the Node';
        $requestedBy = $operation->requestedBy?->name ?: $operation->requestedBy?->email;

        if ($operation->command_type === 'workload.move.v1') {
            $targetName = Node::query()->where('team_id', $this->workload->team_id)
                ->where('uuid', data_get($operation->request, 'target_node_uuid'))->value('name');
            $add($operation->created_at, 'Move to '.($targetName ?? 'another Node').' queued'.($requestedBy ? " by {$requestedBy}." : '.'));
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
            NodeOperationStatus::UNCERTAIN => $add($finishedAt, 'The result is uncertain. Recover the operation from the Node workloads page.', true),
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

    private function deploymentStatusLabel(NodeOperationStatus $status): string
    {
        return match ($status) {
            NodeOperationStatus::QUEUED, NodeOperationStatus::DISPATCHED => 'Queued',
            NodeOperationStatus::RUNNING, NodeOperationStatus::VERIFYING => 'In progress',
            NodeOperationStatus::SUCCEEDED => 'Success',
            NodeOperationStatus::FAILED => 'Failed',
            NodeOperationStatus::TIMED_OUT => 'Timed out',
            NodeOperationStatus::UNCERTAIN => 'Uncertain',
            NodeOperationStatus::CANCELLED => 'Cancelled',
        };
    }

    private function deploymentStatusType(NodeOperationStatus $status): string
    {
        return match ($status) {
            NodeOperationStatus::SUCCEEDED => 'success',
            NodeOperationStatus::FAILED, NodeOperationStatus::TIMED_OUT => 'error',
            NodeOperationStatus::CANCELLED => 'neutral',
            default => 'warning',
        };
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
        $state = DetermineWorkloadState::run($this->node, $this->workload);
        $this->status = str($state->value)->title()->toString();
        $this->statusType = $state->badgeType();
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

    private function loadResourceSettings(): void
    {
        $resources = $this->workload->revisions->first()?->configuration['resources'] ?? [];
        $this->cpuLimit = isset($resources['cpu_limit']) ? (string) $resources['cpu_limit'] : '';
        $this->cpuReservation = isset($resources['cpu_reservation']) ? (string) $resources['cpu_reservation'] : '';
        $this->memoryLimitMb = isset($resources['memory_limit_bytes']) ? (string) ((int) $resources['memory_limit_bytes'] / 1_048_576) : '';
        $this->memoryReservationMb = isset($resources['memory_reservation_bytes']) ? (string) ((int) $resources['memory_reservation_bytes'] / 1_048_576) : '';
    }
}
