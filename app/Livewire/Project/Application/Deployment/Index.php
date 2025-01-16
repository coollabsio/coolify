<?php

namespace App\Livewire\Project\Application\Deployment;

use App\Models\Application;
use Illuminate\Support\Collection;
use Livewire\Component;

class Index extends Component
{
    public Application $application;

    public ?Collection $deployments;

    public int $deployments_count = 0;

    public string $current_url;

    public int $skip = 0;

    public int $default_take = 10;

    public bool $show_next = false;

    public bool $show_prev = false;

    public ?string $pull_request_id = null;

    protected $queryString = ['pull_request_id'];

    public function mount()
    {
        $project = currentTeam()->load(['projects'])->projects->where('uuid', request()->route('project_uuid'))->first();
        if (! $project) {
            return redirect()->route('dashboard');
        }
        $environment = $project->load(['environments'])->environments->where('uuid', request()->route('environment_uuid'))->first()->load(['applications']);
        if (! $environment) {
            return redirect()->route('dashboard');
        }
        $application = $environment->applications->where('uuid', request()->route('application_uuid'))->first();
        if (! $application) {
            return redirect()->route('dashboard');
        }
        ['deployments' => $deployments, 'count' => $count] = $application->deployments(0, $this->default_take);
        $this->application = $application;
        $this->deployments = $deployments;
        $this->deployments_count = $count;
        $this->current_url = url()->current();
        $this->show_pull_request_only();
        $this->show_more();
    }

    private function show_pull_request_only()
    {
        if ($this->pull_request_id) {
            $this->deployments = $this->deployments->where('pull_request_id', $this->pull_request_id);
        }
    }

    private function show_more()
    {
        if ($this->deployments->count() !== 0) {
            $this->show_next = true;
            if ($this->deployments->count() < $this->default_take) {
                $this->show_next = false;
            }

            return;
        }
    }

    public function reload_deployments()
    {
        $this->load_deployments();
    }

    public function previous_page(?int $take = null)
    {
        if ($take) {
            $this->skip = $this->skip - $take;
        }
        $this->skip = $this->skip - $this->default_take;
        if ($this->skip < 0) {
            $this->show_prev = false;
            $this->skip = 0;
        }
        $this->load_deployments();
    }

    public function next_page(?int $take = null)
    {
        if ($take) {
            $this->skip = $this->skip + $take;
        }
        $this->show_prev = true;
        $this->load_deployments();
    }

    public function load_deployments()
    {
        ['deployments' => $deployments, 'count' => $count] = $this->application->deployments($this->skip, $this->default_take);
        $this->deployments = $deployments;
        $this->deployments_count = $count;
        $this->show_pull_request_only();
        $this->show_more();
    }

    public function render()
    {
        return view('livewire.project.application.deployment.index');
    }
}
