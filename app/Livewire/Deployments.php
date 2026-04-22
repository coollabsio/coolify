     1|<?php
     2|
     3|namespace App\Livewire;
     4|
     5|use App\Enums\ApplicationDeploymentStatus;
     6|use App\Models\ApplicationDeploymentQueue;
     7|use Illuminate\Contracts\View\View;
     8|use Illuminate\Database\Eloquent\Builder;
     9|use Illuminate\Support\Collection;
    10|use Livewire\Component;
    11|use Livewire\WithPagination;
    12|
    13|class Deployments extends Component
    14|{
    15|    use WithPagination;
    16|
    17|    public ?string $status = null;
    18|
    19|    public ?string $project = null;
    20|
    21|    public ?string $server = null;
    22|
    23|    public ?string $source = null;
    24|
    25|    protected $queryString = [
    26|        'status' => ['except' => ''],
    27|        'project' => ['except' => ''],
    28|        'server' => ['except' => ''],
    29|        'source' => ['except' => ''],
    30|    ];
    31|
    32|    public function updating(string $name): void
    33|    {
    34|        if (in_array($name, ['status', 'project', 'server', 'source'], true)) {
    35|            $this->resetPage();
    36|        }
    37|    }
    38|
    39|    public function render(): View
    40|    {
    41|        $team = currentTeam();
    42|
    43|        abort_unless($team, 403);
    44|
    45|        $baseQuery = ApplicationDeploymentQueue::query()
    46|            ->with(['application.environment.project'])
    47|            ->whereHas('application.environment.project', function (Builder $query) use ($team) {
    48|                $query->where('team_id', $team->id);
    49|            });
    50|
    51|        $filteredQuery = (clone $baseQuery)
    52|            ->when($this->status, fn (Builder $query) => $query->where('status', $this->status))
    53|            ->when($this->project, function (Builder $query) {
    54|                $query->whereHas('application.environment.project', function (Builder $projectQuery) {
    55|                    $projectQuery->where('name', $this->project);
    56|                });
    57|            })
    58|            ->when($this->server, fn (Builder $query) => $query->where('server_name', $this->server))
    59|            ->when($this->source, fn (Builder $query) => $query->where('git_type', $this->source));
    60|
    61|        $deployments = (clone $filteredQuery)
    62|            ->latest()
    63|            ->paginate(25);
    64|
    65|        return view('livewire.deployments', [
    66|            'deployments' => $deployments,
    67|            'availableProjects' => $this->availableProjects($team->id),
    68|            'availableServers' => $this->distinctValues($baseQuery, 'server_name'),
    69|            'availableSources' => $this->distinctValues($baseQuery, 'git_type'),
    70|            'availableStatuses' => $this->distinctValues($baseQuery, 'status'),
    71|            'isPollingActive' => (clone $filteredQuery)
    72|                ->whereIn('status', [
    73|                    ApplicationDeploymentStatus::QUEUED->value,
    74|                    ApplicationDeploymentStatus::IN_PROGRESS->value,
    75|                ])
    76|                ->exists(),
    77|        ]);
    78|    }
    79|
    80|    private function availableProjects(int $teamId): Collection
    81|    {
    82|        return ApplicationDeploymentQueue::query()
    83|            ->join('applications', 'application_deployment_queues.application_id', '=', 'applications.id')
    84|            ->join('environments', 'applications.environment_id', '=', 'environments.id')
    85|            ->join('projects', 'environments.project_id', '=', 'projects.id')
    86|            ->where('projects.team_id', $teamId)
    87|            ->select('projects.name')
    88|            ->distinct()
    89|            ->orderBy('projects.name')
    90|            ->pluck('projects.name');
    91|    }
    92|
    93|    private function distinctValues(Builder $query, string $column): Collection
    94|    {
    95|        return (clone $query)
    96|            ->select($column)
    97|            ->whereNotNull($column)
    98|            ->whereRaw('TRIM('.$column.") != ''")
    99|            ->distinct()
   100|            ->orderBy($column)
   101|            ->pluck($column);
   102|    }
   103|}
   104|