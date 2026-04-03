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

    public ?string $comment = null;

    public array $problematicVariables = [];

    protected $listeners = ['clearAddEnv' => 'clear'];

    protected $rules = [
        'key' => 'required|string',
        'value' => 'nullable',
        'is_multiline' => 'required|boolean',
        'is_literal' => 'required|boolean',
        'is_runtime' => 'required|boolean',
        'is_buildtime' => 'required|boolean',
        'comment' => 'nullable|string|max:256',
    ];

    protected $validationAttributes = [
        'key' => 'key',
        'value' => 'value',
        'is_multiline' => 'multiline',
        'is_literal' => 'literal',
        'is_runtime' => 'runtime',
        'is_buildtime' => 'buildtime',
        'comment' => 'comment',
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
            'server' => [],
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

        // Get server variables
        $serverUuid = data_get($this->parameters, 'server_uuid');
        if ($serverUuid) {
            // If we have a specific server_uuid, show variables for that server
            $server = \App\Models\Server::where('team_id', $team->id)
                ->where('uuid', $serverUuid)
                ->first();

            if ($server) {
                try {
                    $this->authorize('view', $server);
                    $result['server'] = $server->environment_variables()
                        ->pluck('key')
                        ->toArray();
                } catch (\Illuminate\Auth\Access\AuthorizationException $e) {
                    // User not authorized to view server variables
                }
            }
        } else {
            // For application environment variables, try to use the application's destination server
            $applicationUuid = data_get($this->parameters, 'application_uuid');
            if ($applicationUuid) {
                $application = \App\Models\Application::whereRelation('environment.project.team', 'id', $team->id)
                    ->where('uuid', $applicationUuid)
                    ->with('destination.server')
                    ->first();

                if ($application && $application->destination && $application->destination->server) {
                    try {
                        $this->authorize('view', $application->destination->server);
                        $result['server'] = $application->destination->server->environment_variables()
                            ->pluck('key')
                            ->toArray();
                    } catch (\Illuminate\Auth\Access\AuthorizationException $e) {
                        // User not authorized to view server variables
                    }
                }
            } else {
                // For service environment variables, try to use the service's server
                $serviceUuid = data_get($this->parameters, 'service_uuid');
                if ($serviceUuid) {
                    $service = \App\Models\Service::whereRelation('environment.project.team', 'id', $team->id)
                        ->where('uuid', $serviceUuid)
                        ->with('server')
                        ->first();

                    if ($service && $service->server) {
                        try {
                            $this->authorize('view', $service->server);
                            $result['server'] = $service->server->environment_variables()
                                ->pluck('key')
                                ->toArray();
                        } catch (\Illuminate\Auth\Access\AuthorizationException $e) {
                            // User not authorized to view server variables
                        }
                    }
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
            'is_preview' => $this->is_preview,
            'comment' => $this->comment,
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
        $this->comment = null;
    }
}
