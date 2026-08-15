<?php

namespace App\Livewire\Project\Shared\EnvironmentVariable;

use App\Models\Application;
use App\Models\EnvironmentVariable;
use App\Support\ValidationPatterns;
use App\Traits\EnvironmentVariableProtection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Livewire\Component;

class All extends Component
{
    use AuthorizesRequests, EnvironmentVariableProtection;

    public $resource;

    public string $resourceClass;

    public bool $showPreview = false;

    public ?string $variables = null;

    public ?string $variablesPreview = null;

    public string $view = 'normal';

    public string $search = '';

    public string $environmentFilter = 'all';

    /** @var list<string> */
    public array $variableFilters = [];

    /** @var list<string> */
    public array $serviceFilters = [];

    public string $tableSort = 'default';

    public int $page = 1;

    public int $perPage = 10;

    public function updatedPerPage(): void
    {
        $this->perPage = max(1, min(100, $this->perPage));

        $this->page = 1;
        $this->clearEnvironmentVariableCaches();
    }

    public bool $is_env_sorting_enabled = false;

    public bool $use_build_secrets = false;

    /**
     * Environment variable rows are loaded after first paint via wire:init
     * so the surrounding configuration page can render immediately.
     */
    public bool $readyToLoad = false;

    protected $listeners = [
        'saveKey' => 'submit',
        'refreshEnvs',
        'environmentVariableDeleted' => 'refreshEnvs',
    ];

    public function updatedSearch(): void
    {
        $this->page = 1;
        $this->clearEnvironmentVariableCaches();
    }

    private function clearEnvironmentVariableCaches(): void
    {
        unset($this->environmentVariables);
        unset($this->environmentVariablesPreview);
        unset($this->hardcodedEnvironmentVariables);
        unset($this->hardcodedEnvironmentVariablesPreview);
        unset($this->hasEnvironmentVariables);
        unset($this->environmentVariableRows);
        unset($this->environmentVariablePageRows);
        unset($this->environmentVariableRowCount);
        unset($this->environmentVariableLastPage);
        unset($this->currentEnvironmentVariablePage);
    }

    public function mount()
    {
        $this->is_env_sorting_enabled = data_get($this->resource, 'settings.is_env_sorting_enabled', false);
        $this->use_build_secrets = data_get($this->resource, 'settings.use_build_secrets', false);
        $this->resourceClass = get_class($this->resource);
        $resourceWithPreviews = [Application::class];
        $simpleDockerfile = filled(data_get($this->resource, 'dockerfile'));
        $hasGitRepository = filled(data_get($this->resource, 'git_repository'));
        if (str($this->resourceClass)->contains($resourceWithPreviews) && $hasGitRepository && ! $simpleDockerfile) {
            $this->showPreview = true;
        }
        // Intentionally skip loading env vars / developer-view bulk text here.
        // loadEnvironmentVariables() is triggered from the frontend via wire:init.
    }

    /**
     * Frontend-initiated load of environment variables after the page shell paints.
     */
    public function loadEnvironmentVariables(): void
    {
        if ($this->readyToLoad) {
            return;
        }

        $this->readyToLoad = true;
        $this->clearEnvironmentVariableCaches();

        if ($this->view === 'dev') {
            $this->getDevView();
        }
    }

    public function instantSave()
    {
        try {
            $this->authorize('manageEnvironment', $this->resource);

            $this->page = 1;
            $this->resource->settings->is_env_sorting_enabled = $this->is_env_sorting_enabled;
            $this->resource->settings->use_build_secrets = $this->use_build_secrets;
            $this->resource->settings->save();
            $this->clearEnvironmentVariableCaches();
            if ($this->readyToLoad && $this->view === 'dev') {
                $this->getDevView();
            }
            $this->dispatch('success', 'Environment variable settings updated.');
            $this->dispatch('configurationChanged');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function getEnvironmentVariablesProperty()
    {
        if (! $this->readyToLoad) {
            return collect();
        }

        return $this->getEnvironmentVariables(false);
    }

    public function getEnvironmentVariablesPreviewProperty()
    {
        if (! $this->readyToLoad) {
            return collect();
        }

        return $this->getEnvironmentVariables(true);
    }

    private function getEnvironmentVariables(bool $isPreview, bool $withSearch = true, bool $withValue = true): Collection
    {
        if ($isPreview && ! $this->supportsPreviewEnvironmentVariables()) {
            return collect();
        }

        // Full-value loads (dev view / tests) still use relationship queries.
        // List/table pagination uses fetchManagedEnvironmentVariables() without values.
        $query = $isPreview
            ? $this->resource->environment_variables_preview()
            : $this->resource->environment_variables();

        $query->orderByRaw("CASE WHEN is_required = true AND (value IS NULL OR value = '') THEN 0 ELSE 1 END");

        if ($withSearch && $this->searchTerm() !== '') {
            $escapedSearch = addcslashes(Str::lower($this->searchTerm()), '%_\\');

            $query->whereRaw("LOWER(key) LIKE ? ESCAPE '\\'", ['%'.$escapedSearch.'%']);
        }

        if ($this->is_env_sorting_enabled) {
            $query->orderBy('key');
        } else {
            $query->orderBy('order');
        }

        if (! $withValue) {
            $query->select(self::LIST_COLUMNS);
        }

        $variables = $query->get()->each(fn (EnvironmentVariable $environmentVariable) => $environmentVariable->setAppends([]));

        return $withValue
            ? $this->nullLockedValues($variables)
            : $variables;
    }

    private function searchTerm(): string
    {
        return trim($this->search);
    }

    private function supportsPreviewEnvironmentVariables(): bool
    {
        return $this->showPreview && $this->resource instanceof Application;
    }

    public function getHasEnvironmentVariablesProperty(): bool
    {
        if (! $this->readyToLoad) {
            return false;
        }

        return $this->environmentVariableRowCount > 0;
    }

    private function nullLockedValues($envs)
    {
        $isMember = auth()->user()?->isMember();

        $envs->each(function ($env) use ($isMember) {
            if ($env->is_shown_once || $isMember) {
                $env->value = null;
                $env->real_value = null;
            }
        });

        return $envs;
    }

    public function getIsSearchActiveProperty(): bool
    {
        return $this->searchTerm() !== '';
    }

    public function getHardcodedEnvironmentVariablesProperty()
    {
        if (! $this->readyToLoad) {
            return collect();
        }

        return $this->getHardcodedVariables(false);
    }

    public function getHardcodedEnvironmentVariablesPreviewProperty()
    {
        if (! $this->readyToLoad) {
            return collect();
        }

        return $this->getHardcodedVariables(true);
    }

    /**
     * Full in-memory row list. Prefer page/count helpers for UI rendering so large
     * variable sets stay cheap; this remains for tests and non-paginated callers.
     */
    public function getEnvironmentVariableRowsProperty(): Collection
    {
        if (! $this->readyToLoad) {
            return collect();
        }

        $rows = collect();

        foreach ($this->environmentVariableSegments() as $segment) {
            if ($segment['kind'] === 'managed') {
                foreach ($this->fetchManagedEnvironmentVariables($segment['is_preview'], null, null) as $environmentVariable) {
                    $rows->push($this->managedEnvironmentVariableRow($environmentVariable));
                }

                continue;
            }

            $hardcoded = $segment['is_preview']
                ? $this->hardcodedEnvironmentVariablesPreview
                : $this->hardcodedEnvironmentVariables;

            foreach ($hardcoded->values() as $index => $environmentVariable) {
                $rows->push($this->hardcodedEnvironmentVariableRow(
                    $environmentVariable,
                    $segment['is_preview'],
                    $index,
                ));
            }
        }

        return $rows->values();
    }

    public function getEnvironmentVariablePageRowsProperty(): Collection
    {
        if (! $this->readyToLoad) {
            return collect();
        }

        $page = $this->currentEnvironmentVariablePage;
        $offset = max(0, ($page - 1) * $this->perPage);
        $remaining = $this->perPage;
        $rows = collect();

        foreach ($this->environmentVariableSegments() as $segment) {
            $segmentCount = $segment['count'];

            if ($segmentCount === 0) {
                continue;
            }

            if ($offset >= $segmentCount) {
                $offset -= $segmentCount;

                continue;
            }

            $take = min($remaining, $segmentCount - $offset);

            if ($segment['kind'] === 'managed') {
                $variables = $this->fetchManagedEnvironmentVariables($segment['is_preview'], $offset, $take);
                foreach ($variables as $environmentVariable) {
                    $rows->push($this->managedEnvironmentVariableRow($environmentVariable));
                }
            } else {
                $hardcoded = $segment['is_preview']
                    ? $this->hardcodedEnvironmentVariablesPreview
                    : $this->hardcodedEnvironmentVariables;

                foreach ($hardcoded->slice($offset, $take)->values() as $index => $environmentVariable) {
                    $rows->push($this->hardcodedEnvironmentVariableRow(
                        $environmentVariable,
                        $segment['is_preview'],
                        $offset + $index,
                    ));
                }
            }

            $remaining -= $take;
            $offset = 0;

            if ($remaining <= 0) {
                break;
            }
        }

        return $rows->values();
    }

    public function getEnvironmentVariableRowCountProperty(): int
    {
        if (! $this->readyToLoad) {
            return 0;
        }

        return collect($this->environmentVariableSegments())->sum('count');
    }

    public function getEnvironmentVariableLastPageProperty(): int
    {
        return max(1, (int) ceil($this->environmentVariableRowCount / $this->perPage));
    }

    public function getCurrentEnvironmentVariablePageProperty(): int
    {
        return min($this->page, $this->environmentVariableLastPage);
    }

    public function setEnvironmentFilter(string $filter): void
    {
        if (! in_array($filter, ['all', 'production', 'preview'], true)) {
            return;
        }

        $this->environmentFilter = $filter;
        $this->page = 1;
        $this->clearEnvironmentVariableCaches();
    }

    public function toggleVariableFilter(string $filter): void
    {
        if (! in_array($filter, ['all', 'managed', 'user', 'buildtime', 'runtime', 'multiline', 'literal'], true)) {
            return;
        }

        if ($filter === 'all') {
            $this->variableFilters = [];
        } elseif (in_array($filter, $this->variableFilters, true)) {
            $this->variableFilters = array_values(array_diff($this->variableFilters, [$filter]));
        } else {
            if ($filter === 'managed') {
                $this->variableFilters = array_values(array_diff($this->variableFilters, ['user']));
            } elseif ($filter === 'user') {
                $this->variableFilters = array_values(array_diff($this->variableFilters, ['managed']));
            }
            $this->variableFilters[] = $filter;
        }

        $this->page = 1;
        $this->clearEnvironmentVariableCaches();
    }

    public function setTableSort(string $sort): void
    {
        if (! in_array($sort, ['default', 'name_asc', 'name_desc'], true)) {
            return;
        }

        $this->tableSort = $sort;
        $this->page = 1;
        $this->clearEnvironmentVariableCaches();
    }

    public function toggleServiceFilter(string $service): void
    {
        if (! in_array($service, $this->serviceFilterOptions, true)) {
            return;
        }

        $this->serviceFilters = in_array($service, $this->serviceFilters, true)
            ? array_values(array_diff($this->serviceFilters, [$service]))
            : [...$this->serviceFilters, $service];
        $this->page = 1;
        $this->clearEnvironmentVariableCaches();
    }

    public function clearFilters(): void
    {
        $this->variableFilters = [];
        $this->serviceFilters = [];
        $this->environmentFilter = 'all';
        $this->page = 1;
        $this->clearEnvironmentVariableCaches();
    }

    public function getServiceFilterOptionsProperty(): array
    {
        $compose = $this->resource->docker_compose_raw ?? $this->resource->docker_compose;
        if (blank($compose)) {
            return [];
        }

        return extractHardcodedEnvironmentVariables($compose)
            ->pluck('service_name')
            ->filter()
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    public function setEnvironmentVariablePage(int $page): void
    {
        $this->page = max(1, min($page, $this->environmentVariableLastPage));
        $this->clearEnvironmentVariableCaches();
    }

    public function previousEnvironmentVariablePage(): void
    {
        $this->setEnvironmentVariablePage($this->currentEnvironmentVariablePage - 1);
    }

    public function nextEnvironmentVariablePage(): void
    {
        $this->setEnvironmentVariablePage($this->currentEnvironmentVariablePage + 1);
    }

    /**
     * Ordered segments used for pagination: production managed → production hardcoded
     * → preview managed → preview hardcoded.
     *
     * @return list<array{kind: string, is_preview: bool, count: int}>
     */
    private function environmentVariableSegments(): array
    {
        $includeProduction = $this->environmentFilter === 'all' || $this->environmentFilter === 'production';
        $includePreview = $this->supportsPreviewEnvironmentVariables()
            && ($this->environmentFilter === 'all' || $this->environmentFilter === 'preview');

        $segments = [];

        if ($includeProduction) {
            $segments[] = [
                'kind' => 'managed',
                'is_preview' => false,
                'count' => $this->countManagedEnvironmentVariables(false),
            ];

            if ($this->includesHardcodedVariables() && $this->showsHardcodedEnvironmentVariables()) {
                $segments[] = [
                    'kind' => 'hardcoded',
                    'is_preview' => false,
                    'count' => $this->hardcodedEnvironmentVariables->count(),
                ];
            }

        }

        if ($includePreview) {
            $segments[] = [
                'kind' => 'managed',
                'is_preview' => true,
                'count' => $this->countManagedEnvironmentVariables(true),
            ];

            if ($this->includesHardcodedVariables() && $this->showsHardcodedEnvironmentVariables()) {
                $segments[] = [
                    'kind' => 'hardcoded',
                    'is_preview' => true,
                    'count' => $this->hardcodedEnvironmentVariablesPreview->count(),
                ];
            }

        }

        return $segments;
    }

    private function managedEnvironmentVariablesQuery(bool $isPreview): Builder
    {
        $query = EnvironmentVariable::query()
            ->where('resourceable_type', $this->resource->getMorphClass())
            ->where('resourceable_id', $this->resource->id)
            ->where('is_preview', $isPreview);

        $hardcodedKeys = $this->hardcodedEnvironmentVariableKeys();
        if ($hardcodedKeys !== []) {
            $query->whereNotIn('key', $hardcodedKeys);
        }

        if ($this->serviceFilters !== []) {
            $query->whereRaw('1 = 0');
        }

        $missingRequiredIds = $this->missingRequiredEnvironmentVariableIds($isPreview);
        if ($missingRequiredIds !== []) {
            $placeholders = implode(', ', array_fill(0, count($missingRequiredIds), '?'));
            $query->orderByRaw("CASE WHEN id IN ({$placeholders}) THEN 0 ELSE 1 END", $missingRequiredIds);
        }

        $query->orderByRaw("CASE WHEN key LIKE 'SERVICE_FQDN%' OR key LIKE 'SERVICE_URL%' OR key LIKE 'SERVICE_NAME%' THEN 0 ELSE 1 END");

        if ($this->searchTerm() !== '') {
            $escapedSearch = addcslashes(Str::lower($this->searchTerm()), '%_\\');
            $query->whereRaw("LOWER(key) LIKE ? ESCAPE '\\'", ['%'.$escapedSearch.'%']);
        }

        if (in_array('managed', $this->variableFilters, true) || in_array('user', $this->variableFilters, true)) {
            $method = in_array('managed', $this->variableFilters, true) ? 'where' : 'whereNot';
            $query->{$method}(function (Builder $query): void {
                $query->where('key', 'like', 'SERVICE_FQDN%')
                    ->orWhere('key', 'like', 'SERVICE_URL%')
                    ->orWhere('key', 'like', 'SERVICE_NAME%');
            });
        }

        foreach (['buildtime', 'runtime', 'multiline', 'literal'] as $filter) {
            if (in_array($filter, $this->variableFilters, true)) {
                $query->where('is_'.$filter, true);
            }
        }

        if ($this->tableSort === 'name_asc') {
            $query->orderBy('key');
        } elseif ($this->tableSort === 'name_desc') {
            $query->orderByDesc('key');
        } elseif ($this->is_env_sorting_enabled) {
            $query->orderBy('key');
        } else {
            $query->orderBy('order')->orderBy('id');
        }

        return $query;
    }

    /** @return list<int> */
    private function missingRequiredEnvironmentVariableIds(bool $isPreview): array
    {
        return EnvironmentVariable::query()
            ->where('resourceable_type', $this->resource->getMorphClass())
            ->where('resourceable_id', $this->resource->id)
            ->where('is_preview', $isPreview)
            ->where('is_required', true)
            ->get()
            ->filter(fn (EnvironmentVariable $environmentVariable): bool => $environmentVariable->is_really_required)
            ->pluck('id')
            ->map(fn (int|string $id): int => (int) $id)
            ->values()
            ->all();
    }

    private function countManagedEnvironmentVariables(bool $isPreview): int
    {
        if ($isPreview && ! $this->supportsPreviewEnvironmentVariables()) {
            return 0;
        }

        return $this->managedEnvironmentVariablesQuery($isPreview)->count();
    }

    /**
     * Columns needed to render the table row / nested Show component without decrypting secrets.
     * The encrypted `value` column is intentionally omitted until edit/dev view loads it.
     *
     * @var list<string>
     */
    private const LIST_COLUMNS = [
        'id',
        'uuid',
        'key',
        'comment',
        'order',
        'is_preview',
        'is_multiline',
        'is_literal',
        'is_runtime',
        'is_buildtime',
        'is_required',
        'is_shown_once',
        'is_shared',
        'resourceable_type',
        'resourceable_id',
        'created_at',
        'updated_at',
        'version',
    ];

    private function fetchManagedEnvironmentVariables(?bool $isPreview, ?int $offset, ?int $limit, bool $withValue = false): Collection
    {
        if ($isPreview === true && ! $this->supportsPreviewEnvironmentVariables()) {
            return collect();
        }

        if ($isPreview === null) {
            $variables = collect();

            if ($this->environmentFilter === 'all' || $this->environmentFilter === 'production') {
                $variables = $variables->concat($this->fetchManagedEnvironmentVariables(false, null, null, $withValue));
            }

            if ($this->supportsPreviewEnvironmentVariables()
                && ($this->environmentFilter === 'all' || $this->environmentFilter === 'preview')) {
                $variables = $variables->concat($this->fetchManagedEnvironmentVariables(true, null, null, $withValue));
            }

            return $variables->values();
        }

        $query = $this->managedEnvironmentVariablesQuery($isPreview);

        if (! $withValue) {
            $query->select(self::LIST_COLUMNS);
        }

        if ($offset !== null) {
            $query->skip($offset);
        }

        if ($limit !== null) {
            $query->take($limit);
        }

        $variables = $query->get()->each(function (EnvironmentVariable $environmentVariable) {
            // Prevent accidental real_value / is_shared accessor work during Livewire hydration.
            $environmentVariable->setAppends([]);
        });

        return $withValue
            ? $this->nullLockedValues($variables)
            : $variables;
    }

    private function managedEnvironmentVariableRow(EnvironmentVariable $environmentVariable): array
    {
        return [
            'id' => 'environment-'.$environmentVariable->id,
            'kind' => 'managed',
            'scope' => $environmentVariable->is_preview ? 'preview' : 'production',
            'environmentVariable' => $environmentVariable,
        ];
    }

    private function hardcodedEnvironmentVariableRow(array $environmentVariable, bool $isPreview, int $index): array
    {
        $scope = $isPreview ? 'preview' : 'production';

        return [
            'id' => 'hardcoded-'.$scope.'-'.$environmentVariable['key'].'-'.($environmentVariable['service_name'] ?? 'default').'-'.$index,
            'kind' => 'hardcoded',
            'scope' => $scope,
            'environmentVariable' => $environmentVariable,
        ];
    }

    private function showsHardcodedEnvironmentVariables(): bool
    {
        return $this->resource->type() === 'service' || $this->resource?->build_pack === 'dockercompose';
    }

    private function includesHardcodedVariables(): bool
    {
        return ! in_array('user', $this->variableFilters, true)
            && collect($this->variableFilters)->intersect(['buildtime', 'runtime', 'multiline', 'literal'])->isEmpty();
    }

    protected function getHardcodedVariables(bool $isPreview)
    {
        if ($isPreview && ! $this->supportsPreviewEnvironmentVariables()) {
            return collect([]);
        }

        // Only for services and docker-compose applications
        if ($this->resource->type() !== 'service' &&
            ($this->resourceClass !== 'App\Models\Application' ||
             ($this->resourceClass === 'App\Models\Application' && $this->resource->build_pack !== 'dockercompose'))) {
            return collect([]);
        }

        $dockerComposeRaw = $this->resource->docker_compose_raw ?? $this->resource->docker_compose;

        if (blank($dockerComposeRaw)) {
            return collect([]);
        }

        // Extract all hard-coded variables
        $hardcodedVars = extractHardcodedEnvironmentVariables($dockerComposeRaw);

        // Compose self-references are inputs supplied through Coolify's .env file,
        // not hard-coded values. Keep them editable in the environment variables UI.
        $hardcodedVars = $hardcodedVars->reject(
            fn (array $variable): bool => $this->isSelfReferencingComposeVariable($variable)
        );

        // Filter out magic variables (SERVICE_FQDN_*, SERVICE_URL_*, SERVICE_NAME_*)
        $hardcodedVars = $hardcodedVars->filter(function ($var) {
            $key = $var['key'];

            return ! str($key)->startsWith(['SERVICE_FQDN_', 'SERVICE_URL_', 'SERVICE_NAME_']);
        });

        if ($this->searchTerm() !== '') {
            $hardcodedVars = $hardcodedVars->filter(function ($var) {
                return str($var['key'])->contains($this->searchTerm(), true);
            });
        }

        if ($this->serviceFilters !== []) {
            $hardcodedVars = $hardcodedVars->filter(
                fn ($var) => in_array($var['service_name'] ?? '', $this->serviceFilters, true)
            );
        }

        // Apply sorting based on is_env_sorting_enabled
        if ($this->is_env_sorting_enabled) {
            $hardcodedVars = $hardcodedVars->sortBy('key')->values();
        }
        // Otherwise keep order from docker-compose file

        return $hardcodedVars;
    }

    /** @return list<string> */
    private function hardcodedEnvironmentVariableKeys(): array
    {
        if (! $this->showsHardcodedEnvironmentVariables()) {
            return [];
        }

        $dockerComposeRaw = $this->resource->docker_compose_raw ?? $this->resource->docker_compose;

        if (blank($dockerComposeRaw)) {
            return [];
        }

        $assignments = extractHardcodedEnvironmentVariables($dockerComposeRaw);
        $editableKeys = $assignments
            ->filter(fn (array $variable): bool => $this->isSelfReferencingComposeVariable($variable))
            ->pluck('key')
            ->unique();

        return $assignments
            ->reject(fn (array $variable): bool => $editableKeys->contains($variable['key']))
            ->pluck('key')
            ->reject(fn (string $key): bool => str($key)->startsWith(['SERVICE_FQDN_', 'SERVICE_URL_', 'SERVICE_NAME_']))
            ->unique()
            ->values()
            ->all();
    }

    private function isSelfReferencingComposeVariable(array $variable): bool
    {
        $value = $variable['value'] ?? null;
        if (! is_string($value)) {
            return false;
        }

        if ($value === '$'.$variable['key']) {
            return true;
        }

        $reference = extractBalancedBraceContent($value);
        if ($reference === null || $reference['start'] !== 1 || $reference['end'] !== strlen($value) - 1) {
            return false;
        }

        $splitReference = splitOnOperatorOutsideNested($reference['content']);
        $referencedKey = $splitReference['variable'] ?? $reference['content'];

        return $referencedKey === $variable['key'];
    }

    public function getDevView()
    {
        $this->variables = $this->formatEnvironmentVariables($this->getEnvironmentVariables(false, false));
        if ($this->showPreview) {
            $this->variablesPreview = $this->formatEnvironmentVariables($this->getEnvironmentVariables(true, false));
        }
    }

    private function formatEnvironmentVariables($variables)
    {
        $isMember = auth()->user()?->isMember();

        return $variables->map(function ($item) use ($isMember) {
            if ($isMember) {
                return "$item->key=(Hidden, only admins can view)";
            }
            if ($item->is_shown_once) {
                return "$item->key=(Locked Secret, delete and add again to change)";
            }
            if ($item->is_multiline) {
                return "$item->key=(Multiline environment variable, edit in normal view)";
            }

            return "$item->key=$item->value";
        })->join("\n");
    }

    public function switch()
    {
        $this->view = $this->view === 'normal' ? 'dev' : 'normal';
        if ($this->view === 'dev') {
            $this->ensureEnvironmentVariablesLoaded();
            $this->getDevView();
        }
    }

    public function submit($data = null)
    {
        try {
            $this->authorize('manageEnvironment', $this->resource);
            $this->ensureEnvironmentVariablesLoaded();
            if ($data === null) {
                $this->handleBulkSubmit();
            } else {
                $this->handleSingleSubmit($data);
            }

            $this->updateOrder();
            if ($this->view === 'dev') {
                $this->getDevView();
            }
        } catch (\Throwable $e) {
            return handleError($e, $this);
        } finally {
            $this->refreshEnvs();
        }
    }

    private function ensureEnvironmentVariablesLoaded(): void
    {
        if (! $this->readyToLoad) {
            $this->loadEnvironmentVariables();
        }
    }

    private function updateOrder()
    {
        $variables = $this->normalizeEnvironmentVariables(parseEnvFormatToArray($this->variables));
        $order = 1;
        foreach ($variables as $key => $value) {
            $env = $this->resource->environment_variables()->where('key', $key)->first();
            if ($env) {
                $env->order = $order;
                $env->save();
            }
            $order++;
        }

        if ($this->showPreview) {
            $previewVariables = $this->normalizeEnvironmentVariables(parseEnvFormatToArray($this->variablesPreview));
            $order = 1;
            foreach ($previewVariables as $key => $value) {
                $env = $this->resource->environment_variables_preview()->where('key', $key)->first();
                if ($env) {
                    $env->order = $order;
                    $env->save();
                }
                $order++;
            }
        }
    }

    private function handleBulkSubmit()
    {
        $variables = $this->normalizeEnvironmentVariables(parseEnvFormatToArray($this->variables));
        $changesMade = false;
        $errorOccurred = false;

        // Try to delete removed variables
        $deletedCount = $this->deleteRemovedVariables(false, $variables);
        if ($deletedCount > 0) {
            $changesMade = true;
        } elseif ($deletedCount === 0 && $this->resource->environment_variables()->whereNotIn('key', array_keys($variables))->exists()) {
            // If we tried to delete but couldn't (due to Docker Compose), mark as error
            $errorOccurred = true;
        }

        // Update or create variables
        $updatedCount = $this->updateOrCreateVariables(false, $variables);
        if ($updatedCount > 0) {
            $changesMade = true;
        }

        if ($this->showPreview) {
            $previewVariables = $this->normalizeEnvironmentVariables(parseEnvFormatToArray($this->variablesPreview));

            // Try to delete removed preview variables
            $deletedPreviewCount = $this->deleteRemovedVariables(true, $previewVariables);
            if ($deletedPreviewCount > 0) {
                $changesMade = true;
            } elseif ($deletedPreviewCount === 0 && $this->resource->environment_variables_preview()->whereNotIn('key', array_keys($previewVariables))->exists()) {
                // If we tried to delete but couldn't (due to Docker Compose), mark as error
                $errorOccurred = true;
            }

            // Update or create preview variables
            $updatedPreviewCount = $this->updateOrCreateVariables(true, $previewVariables);
            if ($updatedPreviewCount > 0) {
                $changesMade = true;
            }
        }

        // Only show success message if changes were actually made and no errors occurred
        if ($changesMade && ! $errorOccurred) {
            $this->dispatch('success', 'Environment variables updated.');
        }
    }

    private function handleSingleSubmit($data)
    {
        $data['key'] = ValidationPatterns::validatedEnvironmentVariableKey($data['key']);
        $found = $this->resource->environment_variables()->where('key', $data['key'])->first();
        if ($found) {
            $this->dispatch('error', 'Environment variable already exists.');

            return;
        }

        $maxOrder = $this->resource->environment_variables()->max('order') ?? 0;
        $environment = $this->createEnvironmentVariable($data);
        $environment->order = $maxOrder + 1;
        $environment->save();

        $this->clearEnvironmentVariableCaches();

        $this->dispatch('success', 'Environment variable added.');
    }

    private function createEnvironmentVariable($data)
    {
        $environment = new EnvironmentVariable;
        $environment->key = $data['key'];
        $environment->value = $data['value'];
        $environment->is_multiline = $data['is_multiline'] ?? false;
        $environment->is_literal = $data['is_literal'] ?? false;
        $environment->is_runtime = $data['is_runtime'] ?? true;
        $environment->is_buildtime = $data['is_buildtime'] ?? true;
        $environment->is_preview = $data['is_preview'] ?? false;
        $environment->comment = $data['comment'] ?? null;
        $environment->resourceable_id = $this->resource->id;
        $environment->resourceable_type = $this->resource->getMorphClass();

        return $environment;
    }

    private function deleteRemovedVariables($isPreview, $variables)
    {
        $method = $isPreview ? 'environment_variables_preview' : 'environment_variables';

        // Get all environment variables that will be deleted
        $variablesToDelete = $this->resource->$method()->whereNotIn('key', array_keys($variables))->get();

        // If there are no variables to delete, return 0
        if ($variablesToDelete->isEmpty()) {
            return 0;
        }

        // Check if any of these variables are used in Docker Compose
        if ($this->resource->type() === 'service' || $this->resource->build_pack === 'dockercompose') {
            foreach ($variablesToDelete as $envVar) {
                [$isUsed, $reason] = $this->isEnvironmentVariableUsedInDockerCompose($envVar->key, $this->resource->docker_compose);

                if ($isUsed) {
                    $this->dispatch('error', "Cannot delete environment variable '{$envVar->key}' <br><br>Please remove it from the Docker Compose file first.");

                    return 0;
                }
            }
        }

        // If we get here, no variables are used in Docker Compose, so we can delete them
        $this->resource->$method()->whereNotIn('key', array_keys($variables))->delete();

        return $variablesToDelete->count();
    }

    private function normalizeEnvironmentVariables(array $variables): array
    {
        $normalizedVariables = [];

        foreach ($variables as $key => $data) {
            $normalizedKey = ValidationPatterns::validatedEnvironmentVariableKey((string) $key);

            if (array_key_exists($normalizedKey, $normalizedVariables)) {
                throw new \InvalidArgumentException("Duplicate environment variable key after normalization: {$normalizedKey}.");
            }

            $normalizedVariables[$normalizedKey] = $data;
        }

        return $normalizedVariables;
    }

    private function updateOrCreateVariables($isPreview, $variables)
    {
        $count = 0;
        foreach ($variables as $key => $data) {
            if (str($key)->startsWith('SERVICE_FQDN') || str($key)->startsWith('SERVICE_URL') || str($key)->startsWith('SERVICE_NAME')) {
                continue;
            }

            // Extract value and comment from parsed data
            // Handle both array format ['value' => ..., 'comment' => ...] and plain string values
            $value = is_array($data) ? ($data['value'] ?? '') : $data;
            $comment = is_array($data) ? ($data['comment'] ?? null) : null;

            $method = $isPreview ? 'environment_variables_preview' : 'environment_variables';
            $found = $this->resource->$method()->where('key', $key)->first();

            if ($found) {
                if (! $found->is_shown_once && ! $found->is_multiline) {
                    $changed = false;

                    // Update value if it changed
                    if ($found->value !== $value) {
                        $found->value = $value;
                        $changed = true;
                    }

                    // Only update comment from inline comment if one is provided (overwrites existing)
                    // If $comment is null, don't touch existing comment field to preserve it
                    if ($comment !== null && $found->comment !== $comment) {
                        $found->comment = $comment;
                        $changed = true;
                    }

                    if ($changed) {
                        $found->save();
                        $count++;
                    }
                }
            } else {
                $environment = new EnvironmentVariable;
                $environment->key = $key;
                $environment->value = $value;
                $environment->comment = $comment; // Set comment from inline comment
                $environment->is_multiline = false;
                $environment->is_preview = $isPreview;
                $environment->resourceable_id = $this->resource->id;
                $environment->resourceable_type = $this->resource->getMorphClass();

                $environment->save();
                $count++;
            }
        }

        return $count;
    }

    public function refreshEnvs()
    {
        $this->resource->refresh();
        $this->readyToLoad = true;
        $this->clearEnvironmentVariableCaches();
        if ($this->view === 'dev') {
            $this->getDevView();
        }
    }
}
