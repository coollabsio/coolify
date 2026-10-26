<?php

namespace App\Livewire\Team\Member;

use App\Enums\AccessLevel;
use App\Enums\AccessScope;
use App\Enums\Role;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Scopes\VisibleThroughAccessGrants;
use App\Models\Team;
use App\Models\User;
use App\Services\Access\AccessResolver;
use App\Services\Access\Grant;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Edits the access grants (`team_user.access`) of one member with the `member` role.
 *
 * The members page renders one editor; a member row opens it with the `open-member-access` event.
 * A row target is `team`, or `<scope>:<uuid>` for a project, environment, or resource of the team.
 */
class Access extends Component
{
    use AuthorizesRequests;

    #[Locked]
    public int $teamId;

    #[Locked]
    public ?int $memberId = null;

    public bool $open = false;

    /** @var list<array{key: string, target: string, levels: array<string, bool>}> */
    public array $grants = [];

    public function mount(): void
    {
        $team = currentTeam();
        $this->authorize('manageMembers', $team);
        $this->teamId = $team->id;
    }

    #[On('open-member-access')]
    public function openFor(int $memberId): void
    {
        $this->authorize('manageMembers', Team::findOrFail($this->teamId));
        $this->memberId = $memberId;
        $this->resetValidation();

        $stored = $this->member()->pivot->access;
        $grants = $stored === null ? AccessResolver::defaultMemberGrants() : Grant::listFrom($stored);
        $this->grants = array_map(
            fn (Grant $grant) => $this->row($grant->scope === AccessScope::Team ? 'team' : "{$grant->scope->value}:{$grant->uuid}", $grant->levels),
            $grants,
        );
        $this->open = true;
    }

    public function addGrant(): void
    {
        $this->grants[] = $this->row('', []);
    }

    public function removeGrant(string $key): void
    {
        $this->grants = array_values(array_filter($this->grants, fn (array $row) => $row['key'] !== $key));
    }

    public function applyPreset(string $preset, ?string $projectUuid = null): void
    {
        $this->grants = match ($preset) {
            'viewer' => [$this->row('team', [AccessLevel::Read])],
            'developer' => [$this->row("project:{$projectUuid}", [AccessLevel::Write, AccessLevel::Deploy])],
            'operator' => [$this->row('team', [AccessLevel::Write, AccessLevel::Deploy, AccessLevel::Delete])],
            'full' => [$this->row('team', [AccessLevel::Root])],
            default => $this->grants,
        };
        $this->resetValidation();
    }

    public function save(): void
    {
        $this->authorize('manageMembers', Team::findOrFail($this->teamId));
        $member = $this->member();

        $validTargets = collect($this->scopeOptions)->reject(fn (array $option) => $option['header'] ?? false)->pluck('value')->all();
        $grants = [];
        $errors = [];
        foreach ($this->grants as $index => $row) {
            $target = (string) ($row['target'] ?? '');
            $levels = array_values(array_filter(AccessLevel::cases(), fn (AccessLevel $level) => (bool) ($row['levels'][$level->name] ?? false)));

            if (! in_array($target, $validTargets, true)) {
                $errors["grants.{$index}.target"] = 'Choose a scope in this team.';
            }
            if ($levels === []) {
                $errors["grants.{$index}.levels"] = 'Choose at least one level.';
            }
            if (isset($errors["grants.{$index}.target"]) || isset($errors["grants.{$index}.levels"])) {
                continue;
            }

            [$scope, $uuid] = $target === 'team' ? ['team', null] : explode(':', $target, 2);
            $grants[] = new Grant(AccessScope::from($scope), $uuid, $levels);
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $member->teams()->updateExistingPivot($this->teamId, ['access' => json_encode(Grant::listToArray($grants))]);
        app(AccessResolver::class)->flush();

        auditLog('ui.team_member.access_updated', [
            'team_id' => $this->teamId,
            'member_id' => $member->id,
            'member_name' => $member->name,
            'member_email' => $member->email,
            'access' => Grant::listToArray($grants),
        ]);

        $this->open = false;
        $this->dispatch('success', 'Access saved.');
        $this->dispatch('reloadWindow');
    }

    /**
     * Every project, environment, and resource of the team, for the scope picker.
     * Queries skip the access-grant scope: only owners and admins open this editor, and they see the whole team.
     *
     * @return list<array{value: string, label: string, header?: bool}>
     */
    #[Computed]
    public function scopeOptions(): array
    {
        $projects = Project::withoutGlobalScope(VisibleThroughAccessGrants::class)
            ->where('team_id', $this->teamId)
            ->orderBy('name')
            ->get(['id', 'uuid', 'name']);
        $environments = Environment::withoutGlobalScope(VisibleThroughAccessGrants::class)
            ->whereIn('project_id', $projects->pluck('id'))
            ->get(['id', 'uuid', 'name', 'project_id']);
        $projectNames = $projects->pluck('name', 'id');
        $environmentPaths = $environments->mapWithKeys(fn (Environment $environment) => [
            $environment->id => $projectNames[$environment->project_id].' / '.$environment->name,
        ]);

        $resources = collect(AccessResolver::RESOURCE_MODELS)
            ->flatMap(fn (string $model) => $model::withoutGlobalScope(VisibleThroughAccessGrants::class)
                ->whereIn('environment_id', $environments->pluck('id'))
                ->get(['uuid', 'name', 'environment_id']))
            ->map(fn ($resource) => ['value' => "resource:{$resource->uuid}", 'label' => $environmentPaths[$resource->environment_id].' / '.$resource->name])
            ->sortBy('label', SORT_NATURAL | SORT_FLAG_CASE)
            ->values();

        return [
            ['value' => '__team', 'label' => 'Team', 'header' => true],
            ['value' => 'team', 'label' => 'Whole team'],
            ...($projects->isEmpty() ? [] : [['value' => '__projects', 'label' => 'Projects', 'header' => true]]),
            ...$projects->map(fn (Project $project) => ['value' => "project:{$project->uuid}", 'label' => $project->name])->all(),
            ...($environments->isEmpty() ? [] : [['value' => '__environments', 'label' => 'Environments', 'header' => true]]),
            ...$environments->map(fn (Environment $environment) => ['value' => "environment:{$environment->uuid}", 'label' => $environmentPaths[$environment->id]])
                ->sortBy('label', SORT_NATURAL | SORT_FLAG_CASE)->values()->all(),
            ...($resources->isEmpty() ? [] : [['value' => '__resources', 'label' => 'Resources', 'header' => true]]),
            ...$resources->all(),
        ];
    }

    /**
     * Short description of a member's access for the members list.
     */
    public static function summary(mixed $storedAccess): string
    {
        if ($storedAccess === null) {
            return 'Team-wide read (default)';
        }

        $grants = array_filter(Grant::listFrom($storedAccess), fn (Grant $grant) => $grant->levels !== []);
        if ($grants === []) {
            return 'No access';
        }

        $parts = [];
        foreach ($grants as $grant) {
            if ($grant->scope === AccessScope::Team) {
                $parts[] = 'Team: '.implode(', ', array_map(fn (AccessLevel $level) => Str::lower($level->label()), $grant->levels));
            }
        }
        $counts = array_count_values(array_map(fn (Grant $grant) => $grant->scope->value, $grants));
        foreach ([AccessScope::Project, AccessScope::Environment, AccessScope::Resource] as $scope) {
            if (isset($counts[$scope->value])) {
                $parts[] = $counts[$scope->value].' '.Str::plural($scope->value, $counts[$scope->value]);
            }
        }

        return implode(' + ', $parts);
    }

    public function render(): View
    {
        if ($this->memberId === null) {
            return view('livewire.team.member.access', ['member' => null]);
        }
        $member = $this->member();

        return view('livewire.team.member.access', [
            'member' => $member,
            'team' => Team::findOrFail($this->teamId),
            'currentSummary' => self::summary($member->pivot->access),
            'levels' => AccessLevel::cases(),
            'projectOptions' => collect($this->scopeOptions)->filter(fn (array $option) => str_starts_with($option['value'], 'project:'))->values(),
        ]);
    }

    private function member(): User
    {
        $member = Team::findOrFail($this->teamId)->members()->whereKey($this->memberId)->first();
        abort_if($member === null || $member->pivot->role !== Role::MEMBER->value, 404);

        return $member;
    }

    /**
     * @param  list<AccessLevel>  $levels
     * @return array{key: string, target: string, levels: array<string, bool>}
     */
    private function row(string $target, array $levels): array
    {
        return [
            'key' => Str::random(10),
            'target' => $target,
            'levels' => collect(AccessLevel::cases())->mapWithKeys(fn (AccessLevel $level) => [$level->name => in_array($level, $levels, true)])->all(),
        ];
    }
}
