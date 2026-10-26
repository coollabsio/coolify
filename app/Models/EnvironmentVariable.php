<?php

namespace App\Models;

use App\Exceptions\RemoteSecretException;
use App\Models\EnvironmentVariable as ModelsEnvironmentVariable;
use App\Support\RemoteSecretReferences;
use App\Support\RemoteSecretValueFormatter;
use App\Support\ValidationPatterns;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use OpenApi\Attributes as OA;

#[OA\Schema(
    description: 'Environment Variable model',
    type: 'object',
    properties: [
        'id' => ['type' => 'integer'],
        'uuid' => ['type' => 'string'],
        'resourceable_type' => ['type' => 'string'],
        'resourceable_id' => ['type' => 'integer'],
        'is_literal' => ['type' => 'boolean'],
        'is_multiline' => ['type' => 'boolean'],
        'is_preview' => ['type' => 'boolean'],
        'is_runtime' => ['type' => 'boolean'],
        'is_buildtime' => ['type' => 'boolean'],
        'is_shared' => ['type' => 'boolean'],
        'is_shown_once' => ['type' => 'boolean', 'description' => 'If true, the saved value is hidden in the UI and API responses. MCP never returns environment variable values.'],
        'key' => ['type' => 'string'],
        'value' => ['type' => 'string'],
        'real_value' => ['type' => 'string'],
        'comment' => ['type' => 'string', 'nullable' => true],
        'version' => ['type' => 'string'],
        'created_at' => ['type' => 'string'],
        'updated_at' => ['type' => 'string'],
    ]
)]
class EnvironmentVariable extends BaseModel
{
    use Auditable;

    public const BUILDPACK_CONTROL_VARIABLE_PREFIXES = ['NIXPACKS_', 'RAILPACK_'];

    /**
     * Buildpack control variables that the built image reads when the container starts,
     * e.g. the Nixpacks PHP prestart script renders nginx.conf from NIXPACKS_PHP_ROOT_DIR.
     */
    public const RUNTIME_BUILDPACK_CONTROL_VARIABLES = [
        'NIXPACKS_PHP_ROOT_DIR',
        'NIXPACKS_PHP_FALLBACK_PATH',
        'NIXPACKS_SPA_OUTPUT_DIR',
        'RAILPACK_SKIP_MIGRATIONS',
    ];

    protected $attributes = [
        'is_runtime' => true,
        'is_buildtime' => true,
    ];

    protected $fillable = [
        // Core identification
        'key',
        'value',
        'comment',

        // Polymorphic relationship
        'resourceable_type',
        'resourceable_id',

        // Boolean flags
        'is_preview',
        'is_multiline',
        'is_literal',
        'is_runtime',
        'is_buildtime',
        'is_shown_once',
        'is_shared',
        'is_required',

        // Metadata
        'version',
        'order',
    ];

    protected $casts = [
        'key' => 'string',
        'value' => 'encrypted',
        'preserve_compose_null' => 'boolean',
        'is_multiline' => 'boolean',
        'is_preview' => 'boolean',
        'is_runtime' => 'boolean',
        'is_buildtime' => 'boolean',
        'uses_legacy_escaping' => 'boolean',
        'version' => 'string',
        'resourceable_type' => 'string',
        'resourceable_id' => 'integer',
    ];

    protected $appends = ['real_value', 'is_shared', 'is_really_required', 'is_buildpack_control', 'is_coolify'];

    /**
     * Sensitive fields hidden by default in serialized output (toArray/toJson).
     * API controllers should call makeVisible([...]) for callers with the
     * `read:sensitive` or `root` token ability.
     */
    protected $hidden = [
        'value',
        'real_value',
        // Internal: decides the escaping of variables saved before exact escaping.
        'uses_legacy_escaping',
    ];

    protected static function booted()
    {
        static::created(function (ModelsEnvironmentVariable $environment_variable) {
            if ($environment_variable->resourceable_type === Application::class && ! $environment_variable->is_preview) {
                $found = ModelsEnvironmentVariable::where('key', $environment_variable->key)
                    ->where('resourceable_type', Application::class)
                    ->where('resourceable_id', $environment_variable->resourceable_id)
                    ->where('is_preview', true)
                    ->first();

                if (! $found) {
                    $application = Application::find($environment_variable->resourceable_id);
                    if ($application) {
                        ModelsEnvironmentVariable::create([
                            'key' => $environment_variable->key,
                            'value' => $environment_variable->value,
                            'is_multiline' => $environment_variable->is_multiline ?? false,
                            'is_literal' => $environment_variable->is_literal ?? false,
                            'is_runtime' => $environment_variable->is_runtime ?? false,
                            'is_buildtime' => $environment_variable->is_buildtime ?? false,
                            'is_required' => $environment_variable->is_required ?? false,
                            'is_shown_once' => $environment_variable->is_shown_once ?? false,
                            'comment' => $environment_variable->comment,
                            'resourceable_type' => Application::class,
                            'resourceable_id' => $environment_variable->resourceable_id,
                            'is_preview' => true,
                        ]);
                    }
                }
            }
            $environment_variable->update([
                'version' => config('constants.coolify.version'),
            ]);
        });

        static::saving(function (ModelsEnvironmentVariable $environmentVariable) {
            if ($environmentVariable->preserve_compose_null && $environmentVariable->isDirty('value') && $environmentVariable->value !== null) {
                $environmentVariable->preserve_compose_null = false;
            }
            $environmentVariable->updateIsShared();
        });
    }

    public function preservesLegacyComposeNull(): bool
    {
        return $this->preserve_compose_null && $this->value === null;
    }

    public function service()
    {
        return $this->belongsTo(Service::class);
    }

    public function scopeWithoutBuildpackControlVariables(Builder $query): Builder
    {
        foreach (self::BUILDPACK_CONTROL_VARIABLE_PREFIXES as $prefix) {
            $query->where('key', 'not like', "{$prefix}%");
        }

        return $query;
    }

    public function scopeWithoutBuildOnlyBuildpackControlVariables(Builder $query): Builder
    {
        return $query->where(function (Builder $query) {
            $query->whereIn('key', self::RUNTIME_BUILDPACK_CONTROL_VARIABLES)
                ->orWhere(fn (Builder $query) => $query->withoutBuildpackControlVariables());
        });
    }

    public static function isBuildpackControlKey(?string $key): bool
    {
        if (blank($key)) {
            return false;
        }

        foreach (self::BUILDPACK_CONTROL_VARIABLE_PREFIXES as $prefix) {
            if (str($key)->startsWith($prefix)) {
                return true;
            }
        }

        return false;
    }

    protected function value(): Attribute
    {
        return Attribute::make(
            get: fn (?string $value = null) => $this->get_environment_variables($value),
            set: fn (?string $value = null) => $this->set_environment_variables($value),
        );
    }

    /**
     * Get the parent resourceable model.
     */
    public function resourceable()
    {
        return $this->morphTo();
    }

    public function resource()
    {
        return $this->resourceable;
    }

    /**
     * The value that the container gets: the old escaped form for variables saved before exact
     * escaping, otherwise the resolved value without escaping.
     */
    public function realValue(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->resolveRealValue(),
        );
    }

    /**
     * Resolved value for display and copy. References to locked shared variables keep their
     * reference text, so the UI never reveals a value that is only shown once.
     */
    public function displayRealValue(): ?string
    {
        return $this->resolveRealValue(revealLockedSharedVariables: false);
    }

    /**
     * @param  Collection<int, SharedEnvironmentVariable>|null  $sharedVariables
     */
    private function resolveRealValue(bool $revealLockedSharedVariables = true, bool $escapeLegacyValue = true, ?Collection $sharedVariables = null): ?string
    {
        if (! $this->relationLoaded('resourceable')) {
            $this->load('resourceable');
        }
        $resource = $this->resourceable;
        if (! $resource) {
            return null;
        }

        // Load relationships needed for shared variable resolution
        if (! $resource->relationLoaded('environment')) {
            $resource->load('environment');
        }
        if (! $resource->relationLoaded('server') && method_exists($resource, 'server')) {
            $resource->load('server');
        }
        if (! $resource->relationLoaded('destination') && method_exists($resource, 'destination')) {
            $resource->load('destination.server');
        }

        $value = $this->get_real_environment_variables_internal($this->value, $resource, null, $revealLockedSharedVariables, $sharedVariables);

        return $value === null || ! $escapeLegacyValue || ! $this->uses_legacy_escaping ? $value : $this->legacyEscapedValue($value);
    }

    /**
     * The escaping used before exact escaping.
     */
    private function legacyEscapedValue(string $value): string
    {
        // Skip escaping for valid JSON objects/arrays to prevent quote corruption (see #6160)
        if (json_validate($value) && (str_starts_with($value, '{') || str_starts_with($value, '['))) {
            return $value;
        }

        return $this->is_literal || $this->is_multiline
            ? '\''.$value.'\''
            : escapeEnvVariables($value);
    }

    protected function isReallyRequired(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->is_required && str($this->real_value)->isEmpty(),
        );
    }

    protected function isBuildpackControl(): Attribute
    {
        return Attribute::make(
            get: fn () => self::isBuildpackControlKey($this->key),
        );
    }

    protected function isCoolify(): Attribute
    {
        return Attribute::make(
            get: function () {
                if (str($this->key)->startsWith('SERVICE_')) {
                    return true;
                }

                return false;
            }
        );
    }

    protected function isShared(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->isSharedReference(),
        );
    }

    private function isSharedReference(): bool
    {
        if (blank($this->value)) {
            return false;
        }

        $types = implode('|', SHARED_VARIABLE_TYPES);

        return preg_match('/^{{\s*(?:'.$types.')\..*}}$/s', trim($this->value)) === 1;
    }

    public function get_real_environment_variables_with_server(?string $environment_variable = null, $resource = null, $server = null, bool $revealLockedSharedVariables = true)
    {
        return $this->get_real_environment_variables_internal($environment_variable, $resource, $server, $revealLockedSharedVariables);
    }

    public function getResolvedValueWithServer($server = null)
    {
        if (! $this->relationLoaded('resourceable')) {
            $this->load('resourceable');
        }
        $resource = $this->resourceable;
        if (! $resource) {
            return null;
        }

        // Load relationships needed for shared variable resolution
        if (! $resource->relationLoaded('environment')) {
            $resource->load('environment');
        }
        if (! $resource->relationLoaded('server') && method_exists($resource, 'server')) {
            $resource->load('server');
        }
        if (! $resource->relationLoaded('destination') && method_exists($resource, 'destination')) {
            $resource->load('destination.server');
        }

        $real_value = $this->get_real_environment_variables_internal($this->value, $resource, $server);

        // Skip escaping for valid JSON objects/arrays to prevent quote corruption (see #6160)
        if (json_validate($real_value) && (str_starts_with($real_value, '{') || str_starts_with($real_value, '['))) {
            return $real_value;
        }

        if ($this->is_literal || $this->is_multiline) {
            $real_value = '\''.$real_value.'\'';
        } else {
            $real_value = escapeEnvVariables($real_value);
        }

        return $real_value;
    }

    /** @return array<int, string> */
    /**
     * @param  Collection<int, SharedEnvironmentVariable>|null  $sharedVariables  Preloaded shared variables, to avoid one query per reference.
     */
    public function logRedactionValues(?Collection $sharedVariables = null): array
    {
        $resolvedValue = $this->resolveRealValue(escapeLegacyValue: false, sharedVariables: $sharedVariables);
        if (! is_string($resolvedValue) || $resolvedValue === '') {
            return [];
        }

        // Build-time paths still write the old escaped form, so redact both forms.
        $value = $this->legacyEscapedValue($resolvedValue);
        $values = [$value, $resolvedValue];
        if ($this->is_multiline || $this->is_literal) {
            $unquoted = str_starts_with($value, "'") && str_ends_with($value, "'")
                ? substr($value, 1, -1)
                : $value;
            $values = array_merge($values, self::logRedactionVariants($unquoted, (bool) $this->is_multiline));
        }

        return array_values(array_unique(array_filter($values, static fn (string $item): bool => $item !== '')));
    }

    /**
     * @param  array<array-key, mixed>  $secrets
     * @return array<int, string>
     */
    public static function remoteSecretLogRedactionValues(array $secrets): array
    {
        return collect($secrets)
            ->filter(static fn (mixed $secret): bool => is_string($secret) && $secret !== '')
            ->flatMap(static fn (string $secret): array => [
                ...self::logRedactionVariants($secret, preg_match('/\r|\n/', $secret) === 1),
                RemoteSecretValueFormatter::composeFile($secret),
            ])
            ->filter(static fn (string $item): bool => $item !== '')
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Also covers the value inside a quoted argument such as 'KEY=value'.
     *
     * @return array<int, string>
     */
    private static function logRedactionVariants(string $value, bool $splitLines): array
    {
        $values = [
            $value,
            escapeBashEnvValue($value),
            str_replace("'", "'\\''", $value),
            str_replace(["\r\n", "\r", "\n"], ['\\n', '\\n', '\\n'], $value),
        ];
        if ($splitLines) {
            $values = array_merge($values, preg_split('/\r\n|\r|\n/', $value) ?: []);
        }

        return $values;
    }

    /**
     * Collect the values to hide in a resource's logs.
     *
     * Locked values, database passwords, and resolved remote secrets are always hidden,
     * whatever their length. Other values are hidden unless the resource limits log redaction
     * to locked values only.
     *
     * Remote secrets are fetched at most once per call, and only when a variable references one.
     * Callers that already hold the fetched values, such as deployments, pass $resolveRemoteSecrets = false.
     *
     * @return array{known: array<int, string>, locked: array<int, string>}
     */
    public static function logRedactionSecretsFor(Application|Service|StandalonePostgresql|StandaloneRedis|StandaloneMongodb|StandaloneMysql|StandaloneMariadb|StandaloneKeydb|StandaloneDragonfly|StandaloneClickhouse|StandaloneSqlite|null $resource, bool $includePreview = false, bool $resolveRemoteSecrets = true): array
    {
        if ($resource === null) {
            return ['known' => [], 'locked' => []];
        }

        $isDatabase = ! $resource instanceof Application && ! $resource instanceof Service;
        $secrets = ['known' => [], 'locked' => []];
        if ($isDatabase) {
            foreach (array_keys($resource->getAttributes()) as $attribute) {
                if (str_contains($attribute, 'password')) {
                    $value = $resource->getAttribute($attribute);
                    if (is_string($value) && $value !== '') {
                        $secrets['locked'][] = $value;
                    }
                }
            }
        }

        $variables = collect($isDatabase ? $resource->runtime_environment_variables : $resource->environment_variables);
        if ($includePreview && $resource instanceof Application) {
            $variables = $variables->merge($resource->environment_variables_preview ?? []);
        }
        if ($variables->isEmpty()) {
            return $secrets;
        }

        // Load the resource relations once and share them, so each variable does not load its own copy.
        $resource->loadMissing('environment.project.team');
        if (method_exists($resource, 'server')) {
            $resource->loadMissing('server');
        }
        if (method_exists($resource, 'destination')) {
            $resource->loadMissing('destination.server');
        }
        // Use copies so the resource's own loaded variables do not get a reference back to the resource.
        $variables = $variables->map(fn (self $variable): self => (clone $variable)->setRelation('resourceable', $resource));
        $sharedVariables = self::sharedVariablesReferencedBy($variables, $resource->team()?->id);

        $redactAllValues = $resource->redactsAllEnvValuesInLogs();
        $unresolvedKeys = [];
        $remoteSecretFailure = null;
        foreach ($variables as $variable) {
            if ($resolveRemoteSecrets) {
                $secrets['locked'] = array_merge(
                    $secrets['locked'],
                    self::remoteSecretLogRedactionValuesFor($resource, $variable, $sharedVariables, $remoteSecretFailure, $unresolvedKeys),
                );
            }
            if ($variable->isLockedForLogs($sharedVariables)) {
                $secrets['locked'] = array_merge($secrets['locked'], $variable->logRedactionValues($sharedVariables));
            } elseif ($redactAllValues) {
                $secrets['known'] = array_merge($secrets['known'], $variable->logRedactionValues($sharedVariables));
            }
        }

        if ($remoteSecretFailure !== null) {
            Log::warning('Could not resolve remote secrets for log redaction; their resolved values are not hidden.', [
                'resource_type' => $resource->getMorphClass(),
                'resource_uuid' => $resource->uuid,
                'variables' => array_values(array_unique($unresolvedKeys)),
                'exception' => $remoteSecretFailure::class,
            ]);
        }

        return $secrets;
    }

    /**
     * Resolved remote secret values of a variable; these are always hidden.
     *
     * When the references cannot be resolved, export still works: the reference text and any keys
     * that did resolve are hidden, and the variable is reported. After a failed or empty fetch the
     * source is not asked again.
     *
     * @param  Collection<int, SharedEnvironmentVariable>|null  $sharedVariables
     * @param  array<int, string>  $unresolvedKeys
     * @return array<int, string>
     */
    private static function remoteSecretLogRedactionValuesFor(Application|Service|StandalonePostgresql|StandaloneRedis|StandaloneMongodb|StandaloneMysql|StandaloneMariadb|StandaloneKeydb|StandaloneDragonfly|StandaloneClickhouse|StandaloneSqlite $resource, self $variable, ?Collection $sharedVariables, ?RemoteSecretException &$failure, array &$unresolvedKeys): array
    {
        // Cheap check first, so variables without references never query or contact a source.
        $value = $variable->resolveRealValue(escapeLegacyValue: false, sharedVariables: $sharedVariables);
        if (! RemoteSecretReferences::containsReference($value) || ! $resource->environmentVariableUsesSecretManager($variable)) {
            return [];
        }

        if ($failure === null || $resource->resolvedSecretManagerValuesForRedaction() !== []) {
            try {
                return self::remoteSecretLogRedactionValues([$resource->resolveSecretManagerEnvironmentVariableValue($variable)]);
            } catch (RemoteSecretException $e) {
                $failure = $e;
            }
        }

        $unresolvedKeys[] = $variable->key;
        $available = array_intersect_key($resource->resolvedSecretManagerValuesForRedaction(), array_flip(RemoteSecretReferences::referencedKeys($value)));

        return [...self::remoteSecretLogRedactionValues(array_values($available)), ...$variable->logRedactionValues($sharedVariables)];
    }

    /**
     * A variable is locked when it is locked itself or references a locked shared variable.
     *
     * @param  Collection<int, SharedEnvironmentVariable>|null  $sharedVariables  Preloaded shared variables of the resource's team.
     */
    public function isLockedForLogs(?Collection $sharedVariables = null): bool
    {
        if ($this->is_shown_once) {
            return true;
        }

        $references = self::sharedVariableReferences($this->value);
        if ($references === []) {
            return false;
        }

        $sharedVariables ??= self::sharedVariablesReferencedBy(collect([$this]), $this->resourceable?->team()?->id);
        if ($sharedVariables === null) {
            return true;
        }

        return $sharedVariables->contains(fn (SharedEnvironmentVariable $shared): bool => $shared->is_shown_once
            && in_array(['type' => $shared->type, 'key' => $shared->key], $references, true));
    }

    /**
     * Load every shared variable that the given variables reference, with one query.
     *
     * @param  Collection<int, self>  $variables
     * @return Collection<int, SharedEnvironmentVariable>|null Null when the team is unknown.
     */
    private static function sharedVariablesReferencedBy(Collection $variables, ?int $teamId): ?Collection
    {
        if ($teamId === null) {
            return null;
        }

        $keys = $variables
            ->flatMap(fn (self $variable): array => self::sharedVariableReferences($variable->value))
            ->pluck('key')
            ->unique()
            ->values();

        return $keys->isEmpty()
            ? collect()
            : SharedEnvironmentVariable::query()->where('team_id', $teamId)->whereIn('key', $keys)->get();
    }

    /**
     * @return array<int, array{type: string, key: string}>
     */
    private static function sharedVariableReferences(?string $value): array
    {
        preg_match_all('/{{\s*(\w+)\.([^}\s]+)\s*}}/', (string) $value, $matches, PREG_SET_ORDER);

        return array_map(fn (array $match): array => ['type' => $match[1], 'key' => $match[2]], $matches);
    }

    public function resolveReferencedValue(): ?string
    {
        $value = $this->value;

        if ($this->is_literal || blank($value) || ! str($value)->startsWith('$')) {
            return $value;
        }

        $referencedKey = str($value)->after('$')->trim('{}')->value();

        return static::where('resourceable_type', $this->resourceable_type)
            ->where('resourceable_id', $this->resourceable_id)
            ->where('is_preview', (bool) $this->is_preview)
            ->where('is_shown_once', false)
            ->where('key', $referencedKey)
            ->first()?->value ?? $value;
    }

    /**
     * @param  Collection<int, SharedEnvironmentVariable>|null  $sharedVariables
     */
    private function get_real_environment_variables_internal(?string $environment_variable = null, $resource = null, $serverOverride = null, bool $revealLockedSharedVariables = true, ?Collection $sharedVariables = null)
    {
        if (is_null($environment_variable) || $environment_variable === '' || is_null($resource)) {
            return $environment_variable;
        }
        $environment_variable = trim($environment_variable);
        $sharedEnvsFound = str($environment_variable)->matchAll('/{{(.*?)}}/');
        if ($sharedEnvsFound->isEmpty()) {
            return $environment_variable;
        }
        foreach ($sharedEnvsFound as $sharedEnv) {
            $type = str($sharedEnv)->trim()->match('/(.*?)\./');
            if (! collect(SHARED_VARIABLE_TYPES)->contains($type)) {
                continue;
            }
            $variable = str($sharedEnv)->trim()->match('/\.(.*)/');
            $id = null;
            if ($type->value() === 'environment') {
                $id = $resource->environment->id;
            } elseif ($type->value() === 'project') {
                $id = $resource->environment->project->id;
            } elseif ($type->value() === 'team') {
                $id = $resource->team()->id;
            } elseif ($type->value() === 'server') {
                if ($serverOverride) {
                    $id = $serverOverride->id;
                } elseif (isset($resource->server) && $resource->server) {
                    $id = $resource->server->id;
                } elseif (isset($resource->destination) && $resource->destination && isset($resource->destination->server)) {
                    $id = $resource->destination->server->id;
                }
            }
            if (is_null($id)) {
                continue;
            }
            $found = $sharedVariables !== null
                ? $sharedVariables->first(fn (SharedEnvironmentVariable $shared): bool => $shared->type === $type->value()
                    && $shared->key === $variable->value()
                    && (int) $shared->{"{$type}_id"} === (int) $id)
                : SharedEnvironmentVariable::where('type', $type)
                    ->where('key', $variable)
                    ->where('team_id', $resource->team()->id)
                    ->where("{$type}_id", $id)
                    ->first();
            if ($found && ($revealLockedSharedVariables || ! $found->is_shown_once)) {
                $environment_variable = str($environment_variable)->replace("{{{$sharedEnv}}}", $found->value);
            }
        }

        return str($environment_variable)->value();
    }

    private function get_environment_variables(?string $environment_variable = null): ?string
    {
        if (! $environment_variable) {
            return null;
        }

        return trim(decrypt($environment_variable));
    }

    private function set_environment_variables(?string $environment_variable = null): ?string
    {
        if (is_null($environment_variable)) {
            return null;
        }
        $environment_variable = trim($environment_variable);
        $type = str($environment_variable)->after('{{')->before('.')->value;
        if (str($environment_variable)->startsWith('{{'.$type) && str($environment_variable)->endsWith('}}')) {
            return encrypt($environment_variable);
        }

        return encrypt($environment_variable);
    }

    protected function key(): Attribute
    {
        return Attribute::make(
            set: fn (string $value) => ValidationPatterns::validatedEnvironmentVariableKey(
                ValidationPatterns::normalizeEnvironmentVariableKey($value)
            ),
        );
    }

    protected function updateIsShared(): void
    {
        $this->is_shared = $this->isSharedReference();
    }
}
