<?php

namespace App\Livewire\Deployments;

use App\Models\ApplicationDeploymentQueue;
use App\Models\Server;
use Illuminate\Support\Collection;
use Livewire\Component;

class Index extends Component
{
    public Collection $deployments;

    public Collection $servers;

    public Collection $projects;

    public Collection $sources;

    public string $status = '';

    public string $serverId = '';

    public string $projectUuid = '';

    public string $source = '';

    public int $deploymentsCount = 0;

    protected $queryString = [
        'status' => ['except' => ''],
        'serverId' => ['except' => '', 'as' => 'server'],
        'projectUuid' => ['except' => '', 'as' => 'project'],
        'source' => ['except' => ''],
    ];

    public function getListeners()
    {
        $teamId = auth()->user()->currentTeam()->id;

        return [
            "echo-private:team.{$teamId},ServiceChecked" => 'loadDeployments',
        ];
    }

    public function mount()
    {
        $this->servers = currentTeam()->servers()->orderBy('name')->get();
        $this->projects = currentTeam()->projects()->orderBy('name')->get();
        $this->sources = collect(['manual', 'webhook', 'api', 'rollback', 'pull-request']);
        $this->loadDeployments();
    }

    public function updated()
    {
        $this->loadDeployments();
    }

    public function clearFilters()
    {
        $this->status = '';
        $this->serverId = '';
        $this->projectUuid = '';
        $this->source = '';
        $this->loadDeployments();
    }

    public function loadDeployments()
    {
        $query = ApplicationDeploymentQueue::query()
            ->with('application.environment.project')
            ->whereHas('application.environment.project', function ($query) {
                $query->where('team_id', currentTeam()->id);
            })
            ->latest();

        if ($this->status !== '') {
            $query->where('status', $this->status);
        }

        if ($this->serverId !== '') {
            $query->where('server_id', $this->serverId);
        }

        if ($this->projectUuid !== '') {
            $query->whereHas('application.environment.project', function ($query) {
                $query->where('uuid', $this->projectUuid);
            });
        }

        if ($this->source !== '') {
            match ($this->source) {
                'webhook' => $query->where('is_webhook', true),
                'api' => $query->where('is_api', true),
                'rollback' => $query->where('rollback', true),
                'pull-request' => $query->whereNotNull('pull_request_id'),
                'manual' => $query
                    ->where(function ($query) {
                        $query->where('is_webhook', false)->orWhereNull('is_webhook');
                    })
                    ->where(function ($query) {
                        $query->where('is_api', false)->orWhereNull('is_api');
                    })
                    ->where(function ($query) {
                        $query->where('rollback', false)->orWhereNull('rollback');
                    })
                    ->whereNull('pull_request_id'),
                default => null,
            };
        }

        $this->deploymentsCount = $query->count();
        $this->deployments = $query->limit(50)->get();
    }

    public function deploymentUrl(ApplicationDeploymentQueue $deployment): ?string
    {
        $application = $deployment->application;
        $project = $application?->environment?->project;
        $environment = $application?->environment;

        if (! $application || ! $project || ! $environment) {
            return null;
        }

        return route('project.application.deployment.show', [
            'project_uuid' => $project->uuid,
            'environment_uuid' => $environment->uuid,
            'application_uuid' => $application->uuid,
            'deployment_uuid' => $deployment->deployment_uuid,
        ]);
    }

    public function render()
    {
        return view('livewire.deployments.index');
    }
}
