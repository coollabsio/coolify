<?php

namespace App\Livewire\Project\Application\Deployment;

use App\Enums\ApplicationDeploymentStatus;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
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

    public array $pullRequestOptions = [];

    public string $search = '';

    public string $deploymentFilter = 'all';

    public string $deploymentSort = 'newest';

    public array $statusFilterOptions = [];

    public array $sourceFilterOptions = [];

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

        $this->application = $application;
        $this->loadPullRequestOptions();
        $this->loadDeploymentFilterOptions();
        ['deployments' => $deployments, 'count' => $count] = $application->deployments(
            search: $this->search,
            filter: $this->deploymentFilter,
            sort: $this->deploymentSort,
            take: $this->defaultTake,
            pullRequestId: $this->pull_request_id,
        );
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

    public function goToPage(int $page): void
    {
        $lastPage = max(1, (int) ceil($this->deployments_count / $this->defaultTake));
        $page = max(1, min($page, $lastPage));
        $this->skip = ($page - 1) * $this->defaultTake;
        $this->showPrev = $page > 1;
        $this->updateCurrentPage();
        $this->loadDeployments();
    }

    public function loadDeployments()
    {
        ['deployments' => $deployments, 'count' => $count] = $this->application->deployments(
            skip: $this->skip,
            take: $this->defaultTake,
            pullRequestId: $this->pull_request_id,
            search: $this->search,
            filter: $this->deploymentFilter,
            sort: $this->deploymentSort,
        );
        $this->deployments = $deployments;
        $this->deployments_count = $count;
        $this->showMore();
    }

    public function updatedSearch(): void
    {
        $this->resetPaginationAndLoad();
    }

    public function setDeploymentFilter(string $filter): void
    {
        $validFilters = collect($this->statusFilterOptions)
            ->concat($this->sourceFilterOptions)
            ->pluck('value')
            ->push('all');

        if (! $validFilters->contains($filter)) {
            return;
        }

        $this->deploymentFilter = $filter;
        $this->pull_request_id = null;
        $this->resetPaginationAndLoad();
    }

    public function setPullRequestFilter(string $pullRequestId): void
    {
        $validPullRequestIds = collect($this->pullRequestOptions)->pluck('value');

        if (! $validPullRequestIds->contains($pullRequestId)) {
            return;
        }

        $this->pull_request_id = $pullRequestId === '' ? null : $pullRequestId;
        $this->deploymentFilter = 'all';
        $this->resetPaginationAndLoad();
    }

    public function setDeploymentSort(string $sort): void
    {
        if (! in_array($sort, ['newest', 'oldest'], true)) {
            return;
        }

        $this->deploymentSort = $sort;
        $this->resetPaginationAndLoad();
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

        $this->deploymentFilter = 'all';
        $this->resetPaginationAndLoad();
    }

    public function clearFilter()
    {
        $this->pull_request_id = null;
        $this->deploymentFilter = 'all';
        $this->resetPaginationAndLoad();
    }

    private function updateCurrentPage()
    {
        $this->currentPage = intval($this->skip / $this->defaultTake) + 1;
    }

    private function loadPullRequestOptions(): void
    {
        $pullRequestIds = ApplicationDeploymentQueue::query()
            ->where('application_id', $this->application->id)
            ->where('pull_request_id', '>', 0)
            ->distinct()
            ->orderByDesc('pull_request_id')
            ->pluck('pull_request_id')
            ->map(fn ($pullRequestId) => (string) $pullRequestId)
            ->values();

        if ($this->pull_request_id && ! $pullRequestIds->contains($this->pull_request_id)) {
            $this->pull_request_id = null;
        }

        $this->pullRequestOptions = collect([
            ['value' => '', 'label' => 'All deployments'],
        ])
            ->concat($pullRequestIds->map(fn ($pullRequestId) => [
                'value' => $pullRequestId,
                'label' => "Pull request #{$pullRequestId}",
            ]))
            ->all();
    }

    private function loadDeploymentFilterOptions(): void
    {
        $statuses = ApplicationDeploymentQueue::query()
            ->where('application_id', $this->application->id)
            ->distinct()
            ->pluck('status');

        $statusLabels = [
            ApplicationDeploymentStatus::FINISHED->value => 'Success',
            ApplicationDeploymentStatus::FAILED->value => 'Failed',
            ApplicationDeploymentStatus::IN_PROGRESS->value => 'In progress',
            ApplicationDeploymentStatus::QUEUED->value => 'Queued',
            ApplicationDeploymentStatus::CANCELLED_BY_USER->value => 'Cancelled',
        ];

        $this->statusFilterOptions = collect($statusLabels)
            ->filter(fn (string $label, string $status) => $statuses->contains($status))
            ->map(fn (string $label, string $status) => [
                'value' => "status:{$status}",
                'label' => $label,
            ])
            ->values()
            ->all();

        $sourceKeys = ApplicationDeploymentQueue::query()
            ->where('application_id', $this->application->id)
            ->selectRaw("
                CASE
                    WHEN pull_request_id > 0 THEN 'pull-request'
                    WHEN is_webhook THEN 'webhook'
                    WHEN rollback THEN 'rollback'
                    WHEN is_api THEN 'api'
                    ELSE 'manual'
                END AS source_key
            ")
            ->distinct()
            ->pluck('source_key');

        $sourceLabels = [
            'manual' => 'Manual',
            'pull-request' => 'Pull requests',
            'webhook' => 'Webhooks',
            'api' => 'API',
            'rollback' => 'Rollbacks',
        ];

        $this->sourceFilterOptions = collect($sourceLabels)
            ->filter(fn (string $label, string $source) => $sourceKeys->contains($source))
            ->map(fn (string $label, string $source) => [
                'value' => "source:{$source}",
                'label' => $label,
            ])
            ->values()
            ->all();
    }

    private function resetPaginationAndLoad(): void
    {
        $this->skip = 0;
        $this->showPrev = false;
        $this->updateCurrentPage();
        $this->loadDeployments();
    }

    public function render()
    {
        return view('livewire.project.application.deployment.index');
    }
}
