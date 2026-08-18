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

    public function updatedDefaultTake(): void
    {
        $this->defaultTake = max(1, min(100, $this->defaultTake));

        $this->skip = 0;
        $this->loadDeployments();
    }

    public bool $showNext = false;

    public bool $showPrev = false;

    public int $currentPage = 1;

    public ?string $pull_request_id = null;

    public array $pullRequestOptions = [];

    public string $search = '';

    public array $deploymentFilters = [];

    public string $deploymentSort = 'newest';

    public array $statusFilterOptions = [];

    public array $sourceFilterOptions = [];

    public array $serverFilterOptions = [];

    public bool $embedded = false;

    public ?string $selectedDeploymentUuid = null;

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
        $environment = $project->load(['environments'])->environments->where('uuid', request()->route('environment_uuid'))->first();
        if (! $environment) {
            abort(404);
        }
        $environment->load(['applications']);
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
        if ($this->embedded) {
            $this->defaultTake = 3;
        }
        $this->loadPullRequestOptions();
        $this->loadDeploymentFilterOptions();
        ['deployments' => $deployments, 'count' => $count] = $application->deployments(
            search: $this->search,
            filters: $this->deploymentFilters,
            sort: $this->deploymentSort,
            take: $this->defaultTake,
            pullRequestId: $this->pull_request_id,
        );
        $this->deployments = $deployments;
        $this->deployments_count = $count;
        $this->current_url = route('project.application.deployment.index', [
            'project_uuid' => $project->uuid,
            'environment_uuid' => $environment->uuid,
            'application_uuid' => $application->uuid,
        ]);
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

    public function previousPage(): void
    {
        $this->skip = max(0, $this->skip - $this->defaultTake);
        $this->showPrev = $this->skip > 0;
        $this->updateCurrentPage();
        $this->loadDeployments();
    }

    public function nextPage(): void
    {
        $this->goToPage($this->currentPage + 1);
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
            filters: $this->deploymentFilters,
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

    public function toggleDeploymentFilter(string $filter): void
    {
        $validFilters = collect($this->statusFilterOptions)
            ->concat($this->sourceFilterOptions)
            ->concat($this->serverFilterOptions)
            ->pluck('value')
            ->values();

        if (! $validFilters->contains($filter)) {
            return;
        }

        if (in_array($filter, $this->deploymentFilters, true)) {
            $this->deploymentFilters = array_values(array_diff($this->deploymentFilters, [$filter]));
        } else {
            $this->deploymentFilters[] = $filter;
        }

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
        $this->deploymentFilters = [];
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

        $this->deploymentFilters = [];
        $this->resetPaginationAndLoad();
    }

    public function clearFilter()
    {
        $this->pull_request_id = null;
        $this->deploymentFilters = [];
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

        $servers = ApplicationDeploymentQueue::query()
            ->where('application_id', $this->application->id)
            ->whereNotNull('server_id')
            ->select(['server_id', 'server_name'])
            ->distinct()
            ->orderBy('server_name')
            ->get()
            ->unique('server_id');

        $this->serverFilterOptions = $servers
            ->map(function (ApplicationDeploymentQueue $deployment): array {
                $serverId = (int) $deployment->server_id;

                return [
                    'value' => "server:{$serverId}",
                    'label' => $deployment->server_name ?: "Server #{$serverId}",
                ];
            })
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
