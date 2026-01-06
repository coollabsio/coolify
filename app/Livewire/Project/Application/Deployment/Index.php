<?php

namespace App\Livewire\Project\Application\Deployment;

use App\Enums\ApplicationDeploymentStatus;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\Server;
use Illuminate\Support\Collection;
use Livewire\Component;

class Index extends Component
{
    public Application $application;

    public ?Collection $deployments;

    public int $deployments_count = 0;

    public string $current_url;

    public int $skip = 0;

    public int $defaultTake = 10;

    public bool $showNext = false;

    public bool $showPrev = false;

    public int $currentPage = 1;

    public ?string $pull_request_id = null;

    protected $queryString = ['pull_request_id'];

    public function getListeners()
    {
        $teamId = auth()->user()->currentTeam()->id;

        return [
            "echo-private:team.{$teamId},ServiceChecked" => '$refresh',
        ];
    }

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
        // Validate pull request ID from URL parameters
        if ($this->pull_request_id !== null && $this->pull_request_id !== '') {
            if (! is_numeric($this->pull_request_id) || (float) $this->pull_request_id <= 0 || (float) $this->pull_request_id != (int) $this->pull_request_id) {
                $this->pull_request_id = null;
                $this->dispatch('error', 'Invalid Pull Request ID in URL. Filter cleared.');
            } else {
                // Ensure it's stored as a string representation of a positive integer
                $this->pull_request_id = (string) (int) $this->pull_request_id;
            }
        }

        ['deployments' => $deployments, 'count' => $count] = $application->deployments(0, $this->defaultTake, $this->pull_request_id);
        $this->application = $application;
        $this->deployments = $deployments;
        $this->deployments_count = $count;
        $this->current_url = url()->current();
        $this->updateCurrentPage();
        $this->showMore();
    }

    private function showMore()
    {
        if ($this->deployments->count() !== 0) {
            $this->showNext = true;
            if ($this->deployments->count() < $this->defaultTake) {
                $this->showNext = false;
            }

            return;
        }
    }

    public function reloadDeployments()
    {
        $this->loadDeployments();
    }

    public function previousPage(?int $take = null)
    {
        if ($take) {
            $this->skip = $this->skip - $take;
        }
        $this->skip = $this->skip - $this->defaultTake;
        if ($this->skip < 0) {
            $this->showPrev = false;
            $this->skip = 0;
        }
        $this->updateCurrentPage();
        $this->loadDeployments();
    }

    public function nextPage(?int $take = null)
    {
        if ($take) {
            $this->skip = $this->skip + $take;
        }
        $this->showPrev = true;
        $this->updateCurrentPage();
        $this->loadDeployments();
    }

    public function loadDeployments()
    {
        ['deployments' => $deployments, 'count' => $count] = $this->application->deployments($this->skip, $this->defaultTake, $this->pull_request_id);
        $this->deployments = $deployments;
        $this->deployments_count = $count;
        $this->showMore();
    }

    public function updatedPullRequestId($value)
    {
        // Sanitize and validate the pull request ID
        if ($value !== null && $value !== '') {
            // Check if it's numeric and positive
            if (! is_numeric($value) || (float) $value <= 0 || (float) $value != (int) $value) {
                $this->pull_request_id = null;
                $this->dispatch('error', 'Invalid Pull Request ID. Please enter a valid positive number.');

                return;
            }
            // Ensure it's stored as a string representation of a positive integer
            $this->pull_request_id = (string) (int) $value;
        } else {
            $this->pull_request_id = null;
        }

        // Reset pagination when filter changes
        $this->skip = 0;
        $this->showPrev = false;
        $this->updateCurrentPage();
        $this->loadDeployments();
    }

    public function clearFilter()
    {
        $this->pull_request_id = null;
        $this->skip = 0;
        $this->showPrev = false;
        $this->updateCurrentPage();
        $this->loadDeployments();
    }

    private function updateCurrentPage()
    {
        $this->currentPage = intval($this->skip / $this->defaultTake) + 1;
    }

    public function force_start($deployment_uuid)
    {
        try {
            $deployment = ApplicationDeploymentQueue::where('deployment_uuid', $deployment_uuid)->first();
            if ($deployment) {
                force_start_deployment($deployment);
                $this->loadDeployments();
            }
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function cancel($deployment_uuid)
    {
        try {
            $deployment = ApplicationDeploymentQueue::where('deployment_uuid', $deployment_uuid)->first();
            if (! $deployment) {
                return;
            }

            $kill_command = "docker rm -f {$deployment_uuid}";
            $build_server_id = $deployment->build_server_id ?? $this->application->destination->server_id;
            $server_id = $deployment->server_id ?? $this->application->destination->server_id;

            // First, mark the deployment as cancelled to prevent further processing
            $deployment->update([
                'status' => ApplicationDeploymentStatus::CANCELLED_BY_USER->value,
            ]);

            if ($this->application->settings->is_build_server_enabled) {
                $server = Server::ownedByCurrentTeam()->find($build_server_id);
            } else {
                $server = Server::ownedByCurrentTeam()->find($server_id);
            }

            // Add cancellation log entry
            if ($deployment->logs) {
                $previous_logs = json_decode($deployment->logs, associative: true, flags: JSON_THROW_ON_ERROR);

                $new_log_entry = [
                    'command' => $kill_command,
                    'output' => 'Deployment cancelled by user.',
                    'type' => 'stderr',
                    'timestamp' => now()->toIso8601String(),
                    'hidden' => false,
                    'batch' => 0,
                ];
                $previous_logs[] = $new_log_entry;
                $deployment->update([
                    'logs' => json_encode($previous_logs, flags: JSON_THROW_ON_ERROR),
                ]);
            }

            if ($server) {
                instant_remote_process([$kill_command], $server, false);
            }

            $this->loadDeployments();
            $this->dispatch('success', 'Deployment cancelled.');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function render()
    {
        return view('livewire.project.application.deployment.index');
    }
}
