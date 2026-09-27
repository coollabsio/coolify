<?php

namespace App\Livewire\Project\ClusterApplication;

use App\Actions\Node\CreateDeploymentOperation;
use App\Actions\Node\CreateLifecycleOperation;
use App\Actions\Node\DetermineWorkloadState;
use App\Actions\Node\UpdateNodeWorkloadConfiguration;
use App\Actions\Node\UpdateNodeWorkloadResources;
use App\Enums\NodeOperationStatus;
use App\Enums\NodeWorkloadAction;
use App\Jobs\DeployNodeWorkloadJob;
use App\Jobs\ManageNodeWorkloadJob;
use App\Models\Environment;
use App\Models\Node;
use App\Models\NodeOperation;
use App\Models\NodeWorkload;
use App\Models\Project;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

class Show extends Component
{
    use AuthorizesRequests;

    /** @var list<string> */
    public const SECTIONS = ['general', 'configuration', 'environment-variables', 'resource-limits', 'deployments'];

    #[Locked]
    public string $section = 'general';

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

    public string $environmentVariables = '';

    public function mount(string $project_uuid, string $environment_uuid, string $workload_uuid): void
    {
        $this->project = Project::query()->where('team_id', currentTeam()->id)->where('uuid', $project_uuid)->firstOrFail();
        $this->environment = $this->project->environments()->where('uuid', $environment_uuid)->firstOrFail();
        $this->workload = NodeWorkload::query()->where('team_id', currentTeam()->id)
            ->where('project_id', $this->project->id)->where('environment_id', $this->environment->id)
            ->where('uuid', $workload_uuid)->firstOrFail();
        $this->authorize('view', $this->workload);
        $this->section = $this->resolveSection(request()->route()?->getName());
        $this->loadData();
        $this->loadResourceSettings();
        $this->loadConfiguration();
    }

    public function deploy(): void
    {
        $this->authorize('update', $this->workload);
        $revision = $this->workload->revisions()->latest('id')->firstOrFail();
        $deployment = CreateDeploymentOperation::run($this->node, $revision, auth()->user(), 'newer');
        if ($deployment['created']) {
            DeployNodeWorkloadJob::dispatch($deployment['operation']->id);
            $this->dispatch('success', 'Deployment queued.');
        } else {
            $this->dispatch('info', 'This application already has an active operation.');
        }
        $this->loadData();
    }

    public function refresh(): void
    {
        $this->authorize('view', $this->workload);
        $this->loadData();
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
        $this->dispatch('success', 'Resource settings saved. Redeploy the application to apply them.');
    }

    public function saveConfiguration(): void
    {
        $this->authorize('update', $this->workload);
        $this->validate([
            'startCommand' => ['nullable', 'string', 'max:16384'],
            'environmentVariables' => ['nullable', 'string', 'max:262144'],
        ]);
        $command = $this->parseStartCommand();
        $environment = $this->parseEnvironmentVariables();
        if ($this->getErrorBag()->isNotEmpty()) {
            return;
        }

        UpdateNodeWorkloadConfiguration::run($this->workload, $command, $environment);
        $this->loadData();
        $this->loadConfiguration();
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

    public function render(): View
    {
        return view('livewire.project.cluster-application.show', [
            'image' => $this->workload->revisions->first()?->image,
            'internalHostname' => $this->workload->internal_dns_name
                ? $this->workload->internal_dns_name.'.default.coolify.internal'
                : null,
            'deployments' => $this->section === 'deployments' ? $this->deploymentHistory() : [],
            'routeParameters' => [
                'project_uuid' => $this->project->uuid,
                'environment_uuid' => $this->environment->uuid,
                'workload_uuid' => $this->workload->uuid,
            ],
        ]);
    }

    /** @return list<array{uuid: string, label: string, status: string, statusType: string, created_at: mixed, duration: ?string, error: ?string}> */
    private function deploymentHistory(): array
    {
        return $this->workload->operations
            ->map(fn (NodeOperation $operation): array => [
                'uuid' => $operation->uuid,
                'label' => match ($operation->command_type) {
                    'workload.deploy.v1' => 'Deployment',
                    'workload.lifecycle.v1' => str(data_get($operation->request, 'action', 'lifecycle'))->title()->toString(),
                    'workload.resources.v1' => 'Resource update',
                    'workload.move.v1' => 'Move to another Node',
                    default => str($operation->command_type)->replace('.v1', '')->replace('.', ' ')->title()->toString(),
                },
                'status' => match ($operation->status) {
                    NodeOperationStatus::QUEUED, NodeOperationStatus::DISPATCHED => 'Queued',
                    NodeOperationStatus::RUNNING, NodeOperationStatus::VERIFYING => 'In progress',
                    NodeOperationStatus::SUCCEEDED => 'Success',
                    NodeOperationStatus::FAILED => 'Failed',
                    NodeOperationStatus::TIMED_OUT => 'Timed out',
                    NodeOperationStatus::UNCERTAIN => 'Uncertain',
                    NodeOperationStatus::CANCELLED => 'Cancelled',
                },
                'statusType' => match ($operation->status) {
                    NodeOperationStatus::SUCCEEDED => 'success',
                    NodeOperationStatus::FAILED, NodeOperationStatus::TIMED_OUT => 'error',
                    NodeOperationStatus::CANCELLED => 'neutral',
                    default => 'warning',
                },
                'created_at' => $operation->created_at,
                'duration' => $operation->started_at && $operation->completed_at
                    ? calculateDuration($operation->started_at, $operation->completed_at)
                    : null,
                'error' => $operation->error,
            ])
            ->values()
            ->all();
    }

    private function resolveSection(?string $routeName): string
    {
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
        $this->environmentVariables = '';
        if (auth()->user()?->can('update', $this->workload)) {
            $this->environmentVariables = collect($revision?->environment ?? [])
                ->map(fn (string $value, string $key): string => "{$key}={$value}")
                ->implode("\n");
        }
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

    /** @return array<string, string> */
    private function parseEnvironmentVariables(): array
    {
        $environment = [];
        foreach (preg_split('/\r\n|\r|\n/', $this->environmentVariables) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            if (preg_match('/^([A-Za-z_][A-Za-z0-9_]*)=(.*)$/s', $line, $matches) !== 1) {
                $this->addError('environmentVariables', 'Each line must use KEY=VALUE. Keys can contain letters, numbers, and underscores.');

                return [];
            }
            $value = $matches[2];
            if (strlen($value) >= 2 && in_array($value[0], ['"', "'"], true) && str_ends_with($value, $value[0])) {
                $value = substr($value, 1, -1);
            }
            if (mb_strlen($value) > 4096) {
                $this->addError('environmentVariables', "The value of {$matches[1]} is longer than 4096 characters.");

                return [];
            }
            $environment[$matches[1]] = $value;
        }
        if (count($environment) > 256) {
            $this->addError('environmentVariables', 'Use at most 256 environment variables.');

            return [];
        }

        return $environment;
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
