<?php

namespace App\Livewire\Project\ClusterApplication;

use App\Actions\Node\CreateDeploymentOperation;
use App\Actions\Node\CreateLifecycleOperation;
use App\Actions\Node\DetermineWorkloadState;
use App\Enums\NodeWorkloadAction;
use App\Jobs\DeployNodeWorkloadJob;
use App\Jobs\ManageNodeWorkloadJob;
use App\Models\Environment;
use App\Models\Node;
use App\Models\NodeWorkload;
use App\Models\Project;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\View\View;
use Livewire\Component;

class Show extends Component
{
    use AuthorizesRequests;

    public Project $project;

    public Environment $environment;

    public NodeWorkload $workload;

    public Node $node;

    public string $status = 'Unknown';

    public string $statusType = 'neutral';

    public function mount(string $project_uuid, string $environment_uuid, string $workload_uuid): void
    {
        $this->project = Project::query()->where('team_id', currentTeam()->id)->where('uuid', $project_uuid)->firstOrFail();
        $this->environment = $this->project->environments()->where('uuid', $environment_uuid)->firstOrFail();
        $this->workload = NodeWorkload::query()->where('team_id', currentTeam()->id)
            ->where('project_id', $this->project->id)->where('environment_id', $this->environment->id)
            ->where('uuid', $workload_uuid)->firstOrFail();
        $this->authorize('view', $this->workload);
        $this->loadData();
    }

    public function deploy(): void
    {
        $this->authorize('update', $this->workload);
        $revision = $this->workload->revisions()->latest('id')->firstOrFail();
        $deployment = CreateDeploymentOperation::run($this->node, $revision, auth()->user());
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
        return view('livewire.project.cluster-application.show');
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
}
