<?php

namespace App\Livewire\Project\Shared\EnvironmentVariable;

use App\Models\Environment;
use App\Models\Project;
use App\Traits\EnvironmentVariableAnalyzer;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Computed;
use Livewire\Component;

class Add extends Component
{
    use AuthorizesRequests, EnvironmentVariableAnalyzer;

    public $parameters;

    public bool $shared = false;

    public bool $is_preview = false;

    public string $key;

    public ?string $value = null;

    public bool $is_multiline = false;

    public bool $is_literal = false;

    public bool $is_runtime = true;

    public bool $is_buildtime = true;

    public bool $is_interpolation_only = false;

    public array $service_names = ['all'];

    public string $service_names_input = 'all';

    public string $injection_method = 'environment';

    public array $problematicVariables = [];

    public function updatedServiceNamesInput($value)
    {
        // Convert comma-separated string to array
        if (empty($value)) {
            $this->service_names = ['all'];
        } else {
            $this->service_names = array_map('trim', explode(',', $value));
        }
    }

    public function updatedIsInterpolationOnly($value)
    {
        // If interpolation only is checked, set injection method to 'none'
        if ($value) {
            $this->injection_method = 'none';
        }
    }

    protected $listeners = ['clearAddEnv' => 'clear'];

    protected $rules = [
        'key' => 'required|string',
        'value' => 'nullable',
        'is_multiline' => 'required|boolean',
        'is_literal' => 'required|boolean',
        'is_runtime' => 'required|boolean',
        'is_buildtime' => 'required|boolean',
        'is_interpolation_only' => 'required|boolean',
        'service_names' => 'required|array',
        'injection_method' => 'required|string|in:none,environment,env_file',
    ];

    protected $validationAttributes = [
        'key' => 'key',
        'value' => 'value',
        'is_multiline' => 'multiline',
        'is_literal' => 'literal',
        'is_runtime' => 'runtime',
        'is_buildtime' => 'buildtime',
        'is_interpolation_only' => 'interpolation only',
        'service_names' => 'service names',
        'injection_method' => 'injection method',
    ];

    public function mount()
    {
        $this->parameters = get_route_parameters();
        $this->problematicVariables = self::getProblematicVariablesForFrontend();
    }

    #[Computed]
    public function availableSharedVariables(): array
    {
        $team = currentTeam();
        $result = [
            'team' => [],
            'project' => [],
            'environment' => [],
        ];

        // Early return if no team
        if (! $team) {
            return $result;
        }

        // Check if user can view team variables
        try {
            $this->authorize('view', $team);
            $result['team'] = $team->environment_variables()
                ->pluck('key')
                ->toArray();
        } catch (\Illuminate\Auth\Access\AuthorizationException $e) {
            // User not authorized to view team variables
        }

        // Get project variables if we have a project_uuid in route
        $projectUuid = data_get($this->parameters, 'project_uuid');
        if ($projectUuid) {
            $project = Project::where('team_id', $team->id)
                ->where('uuid', $projectUuid)
                ->first();

            if ($project) {
                try {
                    $this->authorize('view', $project);
                    $result['project'] = $project->environment_variables()
                        ->pluck('key')
                        ->toArray();

                    // Get environment variables if we have an environment_uuid in route
                    $environmentUuid = data_get($this->parameters, 'environment_uuid');
                    if ($environmentUuid) {
                        $environment = $project->environments()
                            ->where('uuid', $environmentUuid)
                            ->first();

                        if ($environment) {
                            try {
                                $this->authorize('view', $environment);
                                $result['environment'] = $environment->environment_variables()
                                    ->pluck('key')
                                    ->toArray();
                            } catch (\Illuminate\Auth\Access\AuthorizationException $e) {
                                // User not authorized to view environment variables
                            }
                        }
                    }
                } catch (\Illuminate\Auth\Access\AuthorizationException $e) {
                    // User not authorized to view project variables
                }
            }
        }

        return $result;
    }

    public function submit()
    {
        $this->validate();
        $this->dispatch('saveKey', [
            'key' => $this->key,
            'value' => $this->value,
            'is_multiline' => $this->is_multiline,
            'is_literal' => $this->is_literal,
            'is_runtime' => $this->is_runtime,
            'is_buildtime' => $this->is_buildtime,
            'is_interpolation_only' => $this->is_interpolation_only,
            'service_names' => $this->service_names,
            'injection_method' => $this->injection_method,
            'is_preview' => $this->is_preview,
        ]);
        $this->clear();
    }

    public function clear()
    {
        $this->key = '';
        $this->value = '';
        $this->is_multiline = false;
        $this->is_literal = false;
        $this->is_runtime = true;
        $this->is_buildtime = true;
        $this->is_interpolation_only = false;
        $this->service_names = ['all'];
        $this->service_names_input = 'all';
        $this->injection_method = 'environment';
    }
}
