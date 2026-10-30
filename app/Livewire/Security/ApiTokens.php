<?php

namespace App\Livewire\Security;

use App\Enums\AccessLevel;
use App\Enums\AccessScope;
use App\Livewire\Forms\ApiTokenForm;
use App\Models\Environment;
use App\Models\Project;
use App\Services\Access\AccessResolver;
use App\Services\Access\Grant;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Laravel\Sanctum\PersonalAccessToken;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

class ApiTokens extends Component
{
    use AuthorizesRequests;

    public ApiTokenForm $form;

    /**
     * The token in the edit modal. The browser fills it from ApiTokenForm::valuesOf(), so opening the modal needs no request.
     */
    public ApiTokenForm $editForm;

    public $tokens = [];

    public array $expirationOptions = [
        7 => '7 days',
        30 => '30 days',
        60 => '60 days',
        90 => '90 days',
        365 => '1 year',
    ];

    #[Locked]
    public bool $canUseRootPermissions = false;

    #[Locked]
    public bool $canUseWritePermissions = false;

    #[Locked]
    public bool $canUseDeployPermissions = false;

    #[Locked]
    public bool $canUseSensitivePermissions = false;

    #[Locked]
    public bool $canUseDeletePermissions = false;

    #[Locked]
    public bool $canUseTeamScope = false;

    public function render()
    {
        return view('livewire.security.api-tokens', [
            'scopeLabels' => collect($this->scopeOptions)->reject(fn (array $option) => $option['header'] ?? false)->pluck('label', 'value'),
        ]);
    }

    /**
     * @param  list<string>  $selected
     * @return list<string>
     */
    private function withoutCoveredScopes(array $selected): array
    {
        $parents = collect($this->scopeOptions)->pluck('parents', 'value');

        return array_values(array_filter(
            array_unique($selected),
            fn (string $value) => array_intersect($parents[$value] ?? [], $selected) === [],
        ));
    }

    /**
     * Projects, environments, and resources of the current team that the user can see, for the scope picker.
     *
     * Each option lists its "parents": the project and environment scopes that already include it.
     *
     * @return list<array{value: string, label: string, header?: bool, parents?: list<string>}>
     */
    #[Computed]
    public function scopeOptions(): array
    {
        $projects = Project::query()->where('team_id', currentTeam()->id)->orderBy('name')->get(['id', 'uuid', 'name']);
        $environments = Environment::query()->whereIn('project_id', $projects->pluck('id'))->get(['id', 'uuid', 'name', 'project_id']);
        $projectNames = $projects->pluck('name', 'id');
        $projectUuids = $projects->pluck('uuid', 'id');
        $environmentParents = $environments->mapWithKeys(fn (Environment $environment) => [
            $environment->id => ["project:{$projectUuids[$environment->project_id]}", "environment:{$environment->uuid}"],
        ]);
        $environmentPaths = $environments->mapWithKeys(fn (Environment $environment) => [
            $environment->id => $projectNames[$environment->project_id].' / '.$environment->name,
        ]);

        $resources = collect(AccessResolver::RESOURCE_MODELS)
            ->flatMap(fn (string $model) => $model::query()->whereIn('environment_id', $environments->pluck('id'))->get(['uuid', 'name', 'environment_id']))
            ->map(fn ($resource) => [
                'value' => "resource:{$resource->uuid}",
                'label' => $environmentPaths[$resource->environment_id].' / '.$resource->name,
                'parents' => $environmentParents[$resource->environment_id],
            ])
            ->sortBy('label', SORT_NATURAL | SORT_FLAG_CASE)
            ->values();

        return [
            ...($projects->isEmpty() ? [] : [['value' => '__projects', 'label' => 'Projects', 'header' => true]]),
            ...$projects->map(fn (Project $project) => ['value' => "project:{$project->uuid}", 'label' => $project->name])->all(),
            ...($environments->isEmpty() ? [] : [['value' => '__environments', 'label' => 'Environments', 'header' => true]]),
            ...$environments->map(fn (Environment $environment) => [
                'value' => "environment:{$environment->uuid}",
                'label' => $environmentPaths[$environment->id],
                'parents' => ["project:{$projectUuids[$environment->project_id]}"],
            ])
                ->sortBy('label', SORT_NATURAL | SORT_FLAG_CASE)->values()->all(),
            ...($resources->isEmpty() ? [] : [['value' => '__resources', 'label' => 'Resources', 'header' => true]]),
            ...$resources->all(),
        ];
    }

    public function mount()
    {
        $this->canUseRootPermissions = auth()->user()->can('useRootPermissions', PersonalAccessToken::class);
        $this->canUseWritePermissions = auth()->user()->can('useWritePermissions', PersonalAccessToken::class);
        $this->canUseDeployPermissions = auth()->user()->can('useDeployPermissions', PersonalAccessToken::class);
        $this->canUseSensitivePermissions = auth()->user()->can('useSensitivePermissions', PersonalAccessToken::class);
        $this->canUseDeletePermissions = auth()->user()->can('useDeletePermissions', PersonalAccessToken::class);
        $this->canUseTeamScope = app(AccessResolver::class)->coversWholeTeam(auth()->user(), currentTeam()->id);
        $this->form->scopeMode = $this->canUseTeamScope ? 'team' : 'selected';
        $this->getTokens();
    }

    private function getTokens()
    {
        $this->tokens = auth()->user()->tokens()
            ->where('team_id', currentTeam()->id)
            ->latest()
            ->get();
    }

    public function addNewToken()
    {
        try {
            $this->authorize('create', PersonalAccessToken::class);

            $teamId = currentTeam()->id;
            ['permissions' => $permissions, 'scopes' => $storedScopes, 'expiresInDays' => $expiresInDays] = $this->validateTokenForm($this->form, $teamId);
            $expiresAt = filled($expiresInDays) ? now()->addDays((int) $expiresInDays) : null;
            // One transaction, so a token never exists team-wide when saving its scopes fails.
            $token = DB::transaction(function () use ($permissions, $expiresAt, $teamId, $storedScopes) {
                $token = auth()->user()->createToken($this->form->description, $permissions, $expiresAt, $teamId);
                $token->accessToken->forceFill(['scopes' => $storedScopes])->save();

                return $token;
            });
            auditLog('ui.api_token.created', [
                'team_id' => $teamId,
                'api_token_name' => $this->form->description,
                'abilities' => $permissions,
                'scopes' => $storedScopes,
                'expires_at' => $expiresAt?->toIso8601String(),
            ]);
            $this->getTokens();
            // Do NOT strip the numeric prefix (e.g. "69|...") — Sanctum uses it to index and look up tokens.
            session()->flash('token', $token->plainTextToken);
        } catch (\Exception $e) {
            return handleError($e, $this);
        }
    }

    /**
     * Save the edited token. The token value does not change, and the next request with it uses the new access.
     */
    public function updateToken(int $id)
    {
        try {
            $token = $this->findOwnToken($id);
            $this->authorize('update', $token);

            $teamId = currentTeam()->id;
            ['permissions' => $permissions, 'scopes' => $storedScopes, 'expiresInDays' => $expiresInDays] = $this->validateTokenForm($this->editForm, $teamId, editing: true);
            $before = ['abilities' => $token->abilities, 'scopes' => $token->scopes, 'expires_at' => $token->expires_at?->toIso8601String()];

            $token->forceFill([
                'name' => $this->editForm->description,
                'abilities' => $permissions,
                'scopes' => $storedScopes,
            ]);
            if ($expiresInDays !== ApiTokenForm::KEEP_EXPIRY) {
                $token->forceFill([
                    'expires_at' => filled($expiresInDays) ? now()->addDays((int) $expiresInDays) : null,
                    // A new expiry date needs a new expiration warning.
                    'api_token_expiration_warning_sent_at' => null,
                ]);
            }
            $token->save();

            auditLog('ui.api_token.updated', [
                'team_id' => $teamId,
                'api_token_name' => $token->name,
                'before' => $before,
                'after' => ['abilities' => $permissions, 'scopes' => $storedScopes, 'expires_at' => $token->expires_at?->toIso8601String()],
            ]);
            $this->editForm->reset();
            $this->getTokens();
            $this->dispatch('api-token-updated');
            $this->dispatch('success', 'API token updated.');
        } catch (\Exception $e) {
            return handleError($e, $this);
        }
    }

    /**
     * Validate the form and check that the user may give the token this access.
     * Never trust the $this->canUse* booleans: check the current user's access in the team again.
     *
     * @return array{permissions: list<string>, scopes: list<array{scope: string, uuid: string}>|null, expiresInDays: int|string|null}
     */
    private function validateTokenForm(ApiTokenForm $form, int $teamId, bool $editing = false): array
    {
        $expiryChoices = array_keys($this->expirationOptions);
        if ($editing) {
            $expiryChoices[] = ApiTokenForm::KEEP_EXPIRY;
        }

        $validated = $form->validate([
            'description' => 'required|min:3|max:255',
            'expiresInDays' => ['nullable', Rule::in($expiryChoices)],
            'permissions' => 'required|array|min:1',
            'permissions.*' => ['required', 'string', Rule::in(['read', 'read:sensitive', 'write', 'write:sensitive', 'deploy', 'delete', 'root'])],
            'scopeMode' => ['required', Rule::in(['team', 'selected'])],
            'selectedScopes' => ['exclude_unless:scopeMode,selected', 'required', 'array', 'min:1'],
            'selectedScopes.*' => ['string', 'regex:/^(project|environment|resource):[A-Za-z0-9_-]+$/'],
        ], [
            'selectedScopes.required' => 'Select at least one project, environment, or resource.',
        ]);
        $permissions = array_values($validated['permissions']);
        $form->selectedScopes = $this->withoutCoveredScopes($form->selectedScopes);
        $scopes = $form->scopeMode === 'selected' ? $this->parseSelectedScopes($form->selectedScopes, $teamId) : null;

        if (in_array('write:sensitive', $permissions, true) && ! auth()->user()->isAdminOfTeam($teamId)) {
            throw new \Exception('You do not have permission to give tokens write:sensitive permissions.');
        }
        $levels = AccessLevel::parseMany($permissions);
        $grants = $scopes === null
            ? [Grant::team($levels)]
            : array_map(fn (Grant $scope) => new Grant($scope->scope, $scope->uuid, $levels), $scopes);
        if (! app(AccessResolver::class)->userHolds(auth()->user(), $teamId, $grants)) {
            throw new \Exception('A token cannot have more access than you have. Choose fewer permissions or narrower projects, environments, or resources.');
        }

        return [
            'permissions' => $permissions,
            'scopes' => $scopes === null ? null : array_map(fn (Grant $scope) => ['scope' => $scope->scope->value, 'uuid' => $scope->uuid], $scopes),
            'expiresInDays' => $validated['expiresInDays'] ?? null,
        ];
    }

    private function findOwnToken(int $id): PersonalAccessToken
    {
        return auth()->user()->tokens()
            ->where('team_id', currentTeam()->id)
            ->where('id', $id)
            ->firstOrFail();
    }

    /**
     * @param  list<string>  $selectedScopes
     * @return list<Grant> scopes without levels; a token's levels are its abilities
     */
    private function parseSelectedScopes(array $selectedScopes, int $teamId): array
    {
        $resolver = app(AccessResolver::class);
        $scopes = [];
        foreach (array_unique($selectedScopes) as $selected) {
            [$scope, $uuid] = explode(':', $selected, 2);
            $grant = new Grant(AccessScope::from($scope), $uuid, []);
            $layer = $resolver->resolve($teamId, [new Grant($grant->scope, $uuid, [AccessLevel::Read])]);
            $existsInTeam = match ($grant->scope) {
                AccessScope::Project => $layer->visibleProjectIds() !== [],
                AccessScope::Environment => $layer->fullEnvironmentIds() !== [],
                default => $layer->resourceUuids() !== [],
            };
            if (! $existsInTeam) {
                throw new \Exception('A selected project, environment, or resource does not exist in this team.');
            }
            $scopes[] = $grant;
        }

        return $scopes;
    }

    /**
     * Pause a token so it stops authenticating requests, or resume a paused token. The token value does not change.
     * The caller sends the wanted state, so a stale page cannot resume a token that another page paused.
     */
    public function setPaused(int $id, bool $paused)
    {
        try {
            $token = $this->findOwnToken($id);
            $this->authorize('update', $token);

            if ($token->isPaused() !== $paused) {
                $token->forceFill(['paused_at' => $paused ? now() : null])->save();
                auditLog($paused ? 'ui.api_token.paused' : 'ui.api_token.resumed', [
                    'team_id' => currentTeam()->id,
                    'api_token_name' => $token->name,
                ]);
            }
            $this->getTokens();
            $this->dispatch('success', $paused ? 'API token paused.' : 'API token resumed.');
        } catch (\Exception $e) {
            return handleError($e, $this);
        }
    }

    public function roll(int $id)
    {
        try {
            $token = $this->findOwnToken($id);
            $this->authorize('update', $token);
            $plainTextToken = $token->roll();
            auditLog('ui.api_token.rolled', [
                'team_id' => $token->team_id,
                'api_token_name' => $token->name,
            ]);
            $this->getTokens();
            session()->flash('token', $plainTextToken);
        } catch (\Exception $e) {
            return handleError($e, $this);
        }
    }

    public function revoke(int $id)
    {
        try {
            $token = $this->findOwnToken($id);
            $this->authorize('delete', $token);
            $tokenName = $token->name;
            $token->delete();
            auditLog('ui.api_token.revoked', [
                'team_id' => currentTeam()->id,
                'api_token_name' => $tokenName,
            ]);
            $this->getTokens();
        } catch (\Exception $e) {
            return handleError($e, $this);
        }
    }
}
