<?php

namespace App\Livewire\Project\Shared\EnvironmentVariable;

use App\Models\Application;
use App\Models\Environment;
use App\Models\EnvironmentVariable as ModelsEnvironmentVariable;
use App\Models\Project;
use App\Models\Server;
use App\Models\Service;
use App\Models\SharedEnvironmentVariable;
use App\Models\StandaloneClickhouse;
use App\Models\StandaloneDragonfly;
use App\Models\StandaloneKeydb;
use App\Models\StandaloneMariadb;
use App\Models\StandaloneMongodb;
use App\Models\StandaloneMysql;
use App\Models\StandalonePostgresql;
use App\Models\StandaloneRedis;
use App\Support\ValidationPatterns;
use App\Traits\EnvironmentVariableAnalyzer;
use App\Traits\EnvironmentVariableProtection;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Validator;
use Livewire\Attributes\Computed;
use Livewire\Component;

class Show extends Component
{
    public bool $showEnvironmentType = true;

    use AuthorizesRequests, EnvironmentVariableAnalyzer, EnvironmentVariableProtection;

    public $parameters;

    public ModelsEnvironmentVariable|SharedEnvironmentVariable $env;

    public bool $isDisabled = false;

    public bool $isLocked = false;

    public bool $isMagicVariable = false;

    public bool $isSharedVariable = false;

    public string $type;

    public int $tableAlphabeticalOrder = 0;

    public int $tableCreationOrder = 0;

    public string $key;

    public ?string $value = null;

    public ?string $real_value = null;

    public ?string $comment = null;

    public bool $is_shared = false;

    public bool $is_multiline = false;

    public bool $is_literal = false;

    public bool $is_shown_once = false;

    public bool $is_runtime = true;

    public bool $is_buildtime = true;

    public bool $is_required = false;

    public bool $is_really_required = false;

    public bool $is_redis_credential = false;

    public bool $isValueHidden = false;

    /**
     * Decrypted value / real_value are only needed in the edit modal (or after save).
     * Keeping them unloaded for table rows avoids decrypting every visible env on each page change.
     */
    public bool $valuesLoaded = false;

    /**
     * Entangled with the edit modal open state so the modal stays open across the
     * async loadValues() re-render (open immediately, decrypt after).
     */
    public bool $editorOpen = false;

    public array $problematicVariables = [];

    public bool $duplicateModalOpen = false;

    /**
     * Duplicate target options are loaded on demand when the duplicate modal
     * opens so table rows stay cheap to render.
     */
    public bool $duplicateOptionsLoaded = false;

    public string $duplicateKey = '';

    /**
     * Resource types that can receive a copied environment variable, keyed by
     * their `type()` slug as used in the duplicate target picker.
     *
     * @var array<string, class-string<Model>>
     */
    private const DUPLICATE_TARGET_TYPES = [
        'application' => Application::class,
        'service' => Service::class,
        'standalone-postgresql' => StandalonePostgresql::class,
        'standalone-mysql' => StandaloneMysql::class,
        'standalone-mariadb' => StandaloneMariadb::class,
        'standalone-mongodb' => StandaloneMongodb::class,
        'standalone-redis' => StandaloneRedis::class,
        'standalone-keydb' => StandaloneKeydb::class,
        'standalone-dragonfly' => StandaloneDragonfly::class,
        'standalone-clickhouse' => StandaloneClickhouse::class,
    ];

    protected $listeners = [
        'refreshEnvs' => 'refresh',
        'refresh',
        'compose_loaded' => '$refresh',
    ];

    protected function rules(): array
    {
        return [
            'key' => ValidationPatterns::environmentVariableKeyRules(),
            'value' => 'nullable',
            'comment' => 'nullable|string|max:256',
            'is_multiline' => 'required|boolean',
            'is_literal' => 'required|boolean',
            'is_shown_once' => 'required|boolean',
            'is_runtime' => 'required|boolean',
            'is_buildtime' => 'required|boolean',
            'real_value' => 'nullable',
            'is_required' => 'required|boolean',
        ];
    }

    protected function messages(): array
    {
        return ValidationPatterns::environmentVariableKeyMessages('key');
    }

    public function mount()
    {
        $this->syncData();
        if ($this->env->getMorphClass() === SharedEnvironmentVariable::class) {
            $this->isSharedVariable = true;
        }
        $this->parameters = get_route_parameters();
        $this->checkEnvs();
        if ($this->type === 'standalone-redis' && ($this->env->key === 'REDIS_PASSWORD' || $this->env->key === 'REDIS_USERNAME')) {
            $this->is_redis_credential = true;
        }
        $this->problematicVariables = self::getProblematicVariablesForFrontend();
    }

    public function getResourceProperty()
    {
        return $this->env->resourceable ?? $this->env;
    }

    public function refresh()
    {
        if (! $this->env->exists || ! $this->env->fresh()) {
            return;
        }
        $this->valuesLoaded = false;
        $this->syncData();
        $this->checkEnvs();
    }

    /**
     * Decrypt and resolve values only when the edit modal is opened.
     */
    public function loadValues(): void
    {
        if ($this->valuesLoaded) {
            return;
        }

        // List queries omit the encrypted value column; refresh so edit has a full model.
        if ($this->env->exists) {
            $fresh = $this->env->fresh();
            if ($fresh) {
                $fresh->setAppends([]);
                $this->env = $fresh;
            }
        }

        $this->hydrateValueFields();
        $this->valuesLoaded = true;
    }

    public function copyValue(): ?string
    {
        if ($this->env->is_shown_once || (auth()->user()?->isMember() ?? true)) {
            return null;
        }

        if (! $this->env instanceof ModelsEnvironmentVariable) {
            return $this->env->value;
        }

        return $this->env->get_real_environment_variables_with_server(
            $this->env->resolveReferencedValue(),
            $this->env->resourceable,
        );
    }

    public function syncData(bool $toModel = false)
    {
        if ($toModel) {
            $this->key = ValidationPatterns::normalizeEnvironmentVariableKey($this->key);

            if ($this->isSharedVariable) {
                $this->validate([
                    'key' => ValidationPatterns::environmentVariableKeyRules(),
                    'value' => 'nullable',
                    'comment' => 'nullable|string|max:256',
                    'is_multiline' => 'required|boolean',
                    'is_literal' => 'required|boolean',
                    'is_shown_once' => 'required|boolean',
                    'real_value' => 'nullable',
                ]);
            } else {
                $this->validate();
                $this->env->is_required = $this->is_required;
                $this->env->is_runtime = $this->is_runtime;
                $this->env->is_buildtime = $this->is_buildtime;
                $this->env->is_shared = $this->is_shared;
            }
            $this->env->key = $this->key;
            $this->env->value = $this->value;
            $this->env->comment = $this->comment;
            $this->env->is_multiline = $this->is_multiline;
            $this->env->is_literal = $this->is_literal;
            $this->env->is_shown_once = $this->is_shown_once;
            $this->env->save();
            $this->valuesLoaded = true;
        } else {
            // Table metadata only — never decrypt here. Values load via loadValues().
            $this->env->setAppends([]);
            $this->key = $this->env->key;
            $this->comment = $this->env->comment;
            $this->is_multiline = (bool) $this->env->is_multiline;
            $this->is_literal = (bool) $this->env->is_literal;
            $this->is_shown_once = (bool) $this->env->is_shown_once;
            $this->is_runtime = (bool) ($this->env->is_runtime ?? true);
            $this->is_buildtime = (bool) ($this->env->is_buildtime ?? true);
            $this->is_required = (bool) ($this->env->is_required ?? false);
            // Use the stored column, not the value-based accessor (that decrypts).
            $this->is_shared = (bool) ($this->env->getAttributes()['is_shared'] ?? false);
            $this->isValueHidden = auth()->user()?->isMember() ?? true;

            if ($this->valuesLoaded) {
                $this->hydrateValueFields();
            } else {
                $this->value = null;
                $this->real_value = null;
                // Required badge: without decrypting, show when flagged required.
                // Exact empty-value state is refined when the edit modal opens.
                $this->is_really_required = $this->is_required;
            }
        }
    }

    private function hydrateValueFields(): void
    {
        $this->value = $this->env->value;
        $this->is_shared = (bool) ($this->env->is_shared ?? false);

        if ($this->is_shared) {
            $this->real_value = $this->env->real_value;
            $this->is_really_required = $this->is_required && blank($this->real_value);
        } else {
            $this->real_value = null;
            $this->is_really_required = $this->is_required && blank($this->value);
        }

        if ($this->env->is_shown_once || (auth()->user()?->isMember() ?? true)) {
            $this->value = null;
            $this->real_value = null;
        }

        $this->isValueHidden = auth()->user()?->isMember() ?? true;
    }

    public function checkEnvs()
    {
        $this->isDisabled = false;
        $this->isMagicVariable = false;

        if (str($this->env->key)->startsWith('SERVICE_FQDN') || str($this->env->key)->startsWith('SERVICE_URL') || str($this->env->key)->startsWith('SERVICE_NAME')) {
            $this->isDisabled = true;
            $this->isMagicVariable = true;
        }

        if ($this->env->is_shown_once) {
            $this->isLocked = true;
        }
    }

    public function serialize()
    {
        data_forget($this->env, 'real_value');
    }

    public function lock()
    {
        $this->authorize('update', $this->env);

        $this->env->is_shown_once = true;
        if ($this->isSharedVariable) {
            unset($this->env->is_required);
        }
        $this->serialize();
        $this->env->save();
        $this->checkEnvs();
        $this->dispatch('refreshEnvs');
    }

    public function instantSave()
    {
        $this->submit();
    }

    public function submit()
    {
        try {
            $this->authorize('update', $this->env);
            $this->loadValues();

            if (! $this->isSharedVariable && $this->is_required && str($this->value)->isEmpty()) {
                $oldValue = $this->env->getOriginal('value');
                $this->value = $oldValue;
                $this->dispatch('error', 'Required environment variables cannot be empty.');

                return;
            }

            $this->serialize();
            $this->syncData(true);
            $this->syncData(false);
            $this->dispatch('success', 'Environment variable updated.');
            $this->dispatch('envsUpdated');
            $this->dispatch('configurationChanged');
        } catch (\Exception $e) {
            return handleError($e);
        }
    }

    #[Computed]
    public function availableSharedVariables(): array
    {
        // Shared across all Show row components in the same request (edit modals).
        static $requestCache = [];

        $team = currentTeam();
        $cacheKey = implode('|', [
            $team?->id ?? 'none',
            data_get($this->parameters, 'project_uuid', ''),
            data_get($this->parameters, 'environment_uuid', ''),
            data_get($this->parameters, 'server_uuid', ''),
            data_get($this->parameters, 'application_uuid', ''),
            data_get($this->parameters, 'service_uuid', ''),
        ]);

        if (array_key_exists($cacheKey, $requestCache)) {
            return $requestCache[$cacheKey];
        }

        $result = [
            'team' => [],
            'project' => [],
            'environment' => [],
            'server' => [],
        ];

        // Early return if no team
        if (! $team) {
            return $requestCache[$cacheKey] = $result;
        }

        // Check if user can view team variables
        try {
            $this->authorize('view', $team);
            $result['team'] = $team->environment_variables()
                ->pluck('key')
                ->toArray();
        } catch (AuthorizationException $e) {
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
                            } catch (AuthorizationException $e) {
                                // User not authorized to view environment variables
                            }
                        }
                    }
                } catch (AuthorizationException $e) {
                    // User not authorized to view project variables
                }
            }
        }

        // Get server variables
        $serverUuid = data_get($this->parameters, 'server_uuid');
        if ($serverUuid) {
            // If we have a specific server_uuid, show variables for that server
            $server = Server::where('team_id', $team->id)
                ->where('uuid', $serverUuid)
                ->first();

            if ($server) {
                try {
                    $this->authorize('view', $server);
                    $result['server'] = $server->environment_variables()
                        ->pluck('key')
                        ->toArray();
                } catch (AuthorizationException $e) {
                    // User not authorized to view server variables
                }
            }
        } else {
            // For application environment variables, try to use the application's destination server
            $applicationUuid = data_get($this->parameters, 'application_uuid');
            if ($applicationUuid) {
                $application = Application::whereRelation('environment.project.team', 'id', $team->id)
                    ->where('uuid', $applicationUuid)
                    ->with('destination.server')
                    ->first();

                if ($application && $application->destination && $application->destination->server) {
                    try {
                        $this->authorize('view', $application->destination->server);
                        $result['server'] = $application->destination->server->environment_variables()
                            ->pluck('key')
                            ->toArray();
                    } catch (AuthorizationException $e) {
                        // User not authorized to view server variables
                    }
                }
            } else {
                // For service environment variables, try to use the service's server
                $serviceUuid = data_get($this->parameters, 'service_uuid');
                if ($serviceUuid) {
                    $service = Service::whereRelation('environment.project.team', 'id', $team->id)
                        ->where('uuid', $serviceUuid)
                        ->with('server')
                        ->first();

                    if ($service && $service->server) {
                        try {
                            $this->authorize('view', $service->server);
                            $result['server'] = $service->server->environment_variables()
                                ->pluck('key')
                                ->toArray();
                        } catch (AuthorizationException $e) {
                            // User not authorized to view server variables
                        }
                    }
                }
            }
        }

        return $requestCache[$cacheKey] = $result;
    }

    public function delete()
    {
        try {
            $this->authorize('delete', $this->env);

            // Check if the variable is used in Docker Compose
            if ($this->type === 'service' || $this->type === 'application' && $this->env->resourceable?->docker_compose) {
                [$isUsed, $reason] = $this->isEnvironmentVariableUsedInDockerCompose($this->env->key, $this->env->resourceable?->docker_compose);

                if ($isUsed) {
                    $this->dispatch('error', "Cannot delete environment variable '{$this->env->key}' <br><br>Please remove it from the Docker Compose file first.");

                    return;
                }
            }

            $this->env->delete();
            $this->dispatch('environmentVariableDeleted');
            $this->dispatch('success', 'Environment variable deleted successfully.');
        } catch (\Exception $e) {
            return handleError($e);
        }
    }

    /**
     * Whether this row offers the duplicate action. Shared, magic, and
     * structural credential variables cannot be duplicated.
     */
    public function canDuplicate(): bool
    {
        return ! $this->isSharedVariable
            && ! $this->isMagicVariable
            && ! $this->is_redis_credential
            && $this->env instanceof ModelsEnvironmentVariable
            && $this->env->exists
            && (auth()->user()?->can('update', $this->env) ?? false);
    }

    /**
     * Prepare the duplicate modal: suggest a collision-free name and load the
     * target picker options. Called when the modal opens.
     */
    public function prepareDuplicate(): void
    {
        if ($this->duplicateOptionsLoaded || ! $this->canDuplicate()) {
            return;
        }

        $this->duplicateKey = $this->suggestedDuplicateKey();
        $this->duplicateOptionsLoaded = true;
    }

    /**
     * Project → environment → resource tree for the duplicate target picker,
     * scoped to the current team.
     *
     * @return list<array{id: int, name: string, environments: list<array{id: int, name: string, resources: list<array{value: string, label: string, current: bool}>}>}>
     */
    #[Computed]
    public function duplicateTargets(): array
    {
        if (! $this->duplicateOptionsLoaded || ! currentTeam()) {
            return [];
        }

        $projects = Project::ownedByCurrentTeamCached()->sortBy('name')->values();

        $resourcesByEnvironment = collect(self::DUPLICATE_TARGET_TYPES)
            ->flatMap(function (string $class, string $type) {
                return $class::query()
                    ->whereIn('environment_id', $this->environmentIdsForDuplicateTargets())
                    ->get(['id', 'name', 'environment_id'])
                    ->map(fn (Model $resource) => [
                        'environment_id' => $resource->environment_id,
                        'value' => "{$type}:{$resource->id}",
                        'label' => $resource->name.' ('.$this->duplicateTargetTypeLabel($type).')',
                        'current' => $this->env->resourceable_type === $class
                            && (int) $this->env->resourceable_id === (int) $resource->id,
                    ]);
            })
            ->groupBy('environment_id');

        return $projects->map(fn (Project $project) => [
            'id' => $project->id,
            'name' => $project->name,
            'environments' => $project->environments->sortBy('name')->values()->map(fn (Environment $environment) => [
                'id' => $environment->id,
                'name' => $environment->name,
                'resources' => $resourcesByEnvironment->get($environment->id, collect())
                    ->map(fn (array $resource) => collect($resource)->except('environment_id')->all())
                    ->sortBy('label')
                    ->values()
                    ->all(),
            ])->all(),
        ])->all();
    }

    /**
     * Duplicate this environment variable onto the given target resource,
     * using the name entered in the duplicate modal.
     */
    public function duplicateVariable(string $target)
    {
        try {
            if (! $this->canDuplicate()) {
                return;
            }

            $this->authorize('update', $this->env);

            // Table rows omit the encrypted value column; fetch a full model.
            $source = ModelsEnvironmentVariable::query()->findOrFail($this->env->id);

            $validator = Validator::make(
                ['duplicateKey' => ValidationPatterns::normalizeEnvironmentVariableKey($this->duplicateKey)],
                ['duplicateKey' => ValidationPatterns::environmentVariableKeyRules()],
                ValidationPatterns::environmentVariableKeyMessages('duplicateKey', 'name'),
            );
            if ($validator->fails()) {
                $this->dispatch('error', $validator->errors()->first('duplicateKey'));

                return;
            }
            $newKey = $validator->validated()['duplicateKey'];

            if (str($newKey)->startsWith(['SERVICE_FQDN', 'SERVICE_URL', 'SERVICE_NAME'])) {
                $this->dispatch('error', 'Names starting with SERVICE_FQDN, SERVICE_URL or SERVICE_NAME are reserved for magic variables.');

                return;
            }

            $targetResource = $this->resolveDuplicateTarget($target);
            if (! $targetResource || $targetResource->team()?->id !== $source->resourceable?->team()?->id) {
                $this->dispatch('error', 'Target resource not found.');

                return;
            }

            $this->authorize('manageEnvironment', $targetResource);

            // Preview variables only exist on applications; kept scope otherwise drops to production.
            $isPreview = $source->is_preview && $targetResource instanceof Application;

            $keyTaken = ModelsEnvironmentVariable::query()
                ->where('resourceable_type', $targetResource->getMorphClass())
                ->where('resourceable_id', $targetResource->id)
                ->where('is_preview', $isPreview)
                ->where('key', $newKey)
                ->exists();
            if ($keyTaken) {
                $this->dispatch('error', "Environment variable '{$newKey}' already exists on the target resource.");

                return;
            }

            $maxOrder = ModelsEnvironmentVariable::query()
                ->where('resourceable_type', $targetResource->getMorphClass())
                ->where('resourceable_id', $targetResource->id)
                ->where('is_preview', $isPreview)
                ->max('order') ?? 0;

            $duplicate = $source->replicate(['id', 'uuid', 'created_at', 'updated_at', 'version']);
            $duplicate->key = $newKey;
            $duplicate->resourceable_type = $targetResource->getMorphClass();
            $duplicate->resourceable_id = $targetResource->id;
            $duplicate->is_preview = $isPreview;
            // Required is a property of the original template variable, not of copies.
            $duplicate->is_required = false;
            $duplicate->order = $maxOrder + 1;
            $duplicate->save();

            $this->duplicateModalOpen = false;
            $this->duplicateOptionsLoaded = false;
            $this->duplicateKey = '';

            $isSameResource = $targetResource->getMorphClass() === $source->resourceable_type
                && (int) $targetResource->id === (int) $source->resourceable_id;
            if ($isSameResource) {
                $this->dispatch('refreshEnvs');
                $this->dispatch('configurationChanged');
                $this->dispatch('success', 'Environment variable duplicated.');
            } else {
                $this->dispatch('success', "Environment variable copied to '{$targetResource->name}'.");
            }
        } catch (\Exception $e) {
            return handleError($e);
        }
    }

    /** @return list<int> */
    private function environmentIdsForDuplicateTargets(): array
    {
        return Project::ownedByCurrentTeamCached()
            ->flatMap(fn (Project $project) => $project->environments->pluck('id'))
            ->map(fn (int|string $id): int => (int) $id)
            ->all();
    }

    private function duplicateTargetTypeLabel(string $type): string
    {
        return match ($type) {
            'application' => 'Application',
            'service' => 'Service',
            default => 'Database',
        };
    }

    private function resolveDuplicateTarget(string $target): ?Model
    {
        [$type, $id] = array_pad(explode(':', $target, 2), 2, null);

        $class = self::DUPLICATE_TARGET_TYPES[$type] ?? null;
        if ($class === null || ! ctype_digit((string) $id)) {
            return null;
        }

        return $class::query()->find((int) $id);
    }

    /**
     * First collision-free "KEY_COPY" style name on the source resource.
     */
    private function suggestedDuplicateKey(): string
    {
        $base = mb_substr($this->env->key, 0, 240);
        $candidate = $base.'_COPY';
        $suffix = 2;

        while ($this->duplicateKeyExistsOnSource($candidate) && $suffix <= 100) {
            $candidate = $base.'_COPY'.$suffix;
            $suffix++;
        }

        return $candidate;
    }

    private function duplicateKeyExistsOnSource(string $key): bool
    {
        return ModelsEnvironmentVariable::query()
            ->where('resourceable_type', $this->env->resourceable_type)
            ->where('resourceable_id', $this->env->resourceable_id)
            ->where('is_preview', (bool) $this->env->is_preview)
            ->where('key', $key)
            ->exists();
    }
}
