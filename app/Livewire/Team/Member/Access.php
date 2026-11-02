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
 * A row has one or more targets, and its levels apply to each of them: `team`, or `<scope>:<uuid>`
 * for a project, environment, or resource of the team. Saving stores one grant for each target.
 *
 * The invite form opens the same editor with `open-invitation-access`. Then saving sends the grants
 * back to the invite form with `invitation-access-chosen` and stores nothing.
 */
class Access extends Component
{
    use AuthorizesRequests;

    #[Locked]
    public int $teamId;

    #[Locked]
    public ?int $memberId = null;

    #[Locked]
    public bool $forInvitation = false;

    public bool $open = false;

    /** @var list<array{key: string, targets: list<string>, levels: array<string, bool>}> */
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
        $this->forInvitation = false;
        $this->resetValidation();

        $stored = $this->member()->pivot->access;
        $this->grants = $this->rowsFrom($stored === null ? AccessResolver::defaultMemberGrants() : Grant::listFrom($stored));
        $this->open = true;
    }

    /**
     * @param  list<array{scope: string, uuid: string|null, levels: list<string>}>  $access
     */
    #[On('open-invitation-access')]
    public function openForInvitation(array $access): void
    {
        $this->authorize('manageInvitations', Team::findOrFail($this->teamId));
        $this->memberId = null;
        $this->forInvitation = true;
        $this->resetValidation();

        $this->grants = $this->rowsFrom(Grant::listFrom($access));
        $this->open = true;
    }

    /**
     * The grants of a preset, or null for an unknown preset. The developer preset needs a project UUID.
     *
     * @return list<Grant>|null
     */
    public static function presetGrants(string $preset, ?string $projectUuid = null): ?array
    {
        return match ($preset) {
            'viewer' => [Grant::team([AccessLevel::Read])],
            'developer' => filled($projectUuid) ? [new Grant(AccessScope::Project, $projectUuid, [AccessLevel::Write, AccessLevel::Deploy])] : null,
            'operator' => [Grant::team([AccessLevel::Write, AccessLevel::Deploy, AccessLevel::Delete])],
            'full' => [Grant::team([AccessLevel::Root])],
            default => null,
        };
    }

    public function save(): void
    {
        if ($this->forInvitation) {
            $this->authorize('manageInvitations', Team::findOrFail($this->teamId));
            $grants = $this->validatedGrants();
            $this->dispatch('invitation-access-chosen', access: Grant::listToArray($grants));
            $this->open = false;

            return;
        }

        $this->authorize('manageMembers', Team::findOrFail($this->teamId));
        $member = $this->member();
        $grants = $this->validatedGrants();

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
     * Grant targets (`team` or `<scope>:<uuid>`) that exist in a team.
     *
     * @return list<string>
     */
    public static function validTargets(int $teamId): array
    {
        return collect(self::scopeOptionsFor($teamId))->reject(fn (array $option) => $option['header'] ?? false)->pluck('value')->all();
    }

    /**
     * @return list<Grant>
     */
    private function validatedGrants(): array
    {
        $validTargets = self::validTargets($this->teamId);
        $grants = [];
        $errors = [];
        foreach ($this->grants as $index => $row) {
            $targets = array_values(array_unique(array_map('strval', (array) ($row['targets'] ?? []))));
            $levels = array_values(array_filter(AccessLevel::cases(), fn (AccessLevel $level) => (bool) ($row['levels'][$level->name] ?? false)));

            if ($targets === [] || array_diff($targets, $validTargets) !== []) {
                $errors["grants.{$index}.targets"] = 'Choose at least one scope in this team.';
            }
            if ($levels === []) {
                $errors["grants.{$index}.levels"] = 'Choose at least one level.';
            }
            if (isset($errors["grants.{$index}.targets"]) || isset($errors["grants.{$index}.levels"])) {
                continue;
            }

            foreach ($targets as $target) {
                [$scope, $uuid] = $target === 'team' ? ['team', null] : explode(':', $target, 2);
                $grants[] = new Grant(AccessScope::from($scope), $uuid, $levels);
            }
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $grants;
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
        return self::scopeOptionsFor($this->teamId);
    }

    /**
     * @return list<array{value: string, label: string, header?: bool}>
     */
    private static function scopeOptionsFor(int $teamId): array
    {
        $projects = Project::withoutGlobalScope(VisibleThroughAccessGrants::class)
            ->where('team_id', $teamId)
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
        if ($this->forInvitation) {
            $title = 'Access for the invited member';
            $currentSummary = null;
        } elseif ($this->memberId !== null) {
            $member = $this->member();
            $title = "Access for {$member->name}";
            $currentSummary = self::summary($member->pivot->access);
        } else {
            return view('livewire.team.member.access', ['title' => null]);
        }

        return view('livewire.team.member.access', [
            'title' => $title,
            'team' => Team::findOrFail($this->teamId),
            'currentSummary' => $currentSummary,
            'levels' => collect(AccessLevel::cases())->map(fn (AccessLevel $level) => [
                'name' => $level->name,
                'label' => $level->label(),
                'description' => $level->description(),
                'includes' => array_map(fn (AccessLevel $included) => $included->name, $level->includes()),
            ])->all(),
            'presets' => collect(['viewer', 'developer', 'operator', 'full'])->mapWithKeys(fn (string $preset) => [
                $preset => $this->rowsFrom(self::presetGrants($preset, '__project__'))[0],
            ])->all(),
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
     * Editor rows for stored grants. Grants with the same levels share one row.
     *
     * @param  list<Grant>  $grants
     * @return list<array{key: string, targets: list<string>, levels: array<string, bool>}>
     */
    private function rowsFrom(array $grants): array
    {
        return collect($grants)
            ->groupBy(fn (Grant $grant) => implode(',', array_map(fn (AccessLevel $level) => $level->name, $grant->levels)))
            ->map(fn ($group) => $this->row(
                $group->map(fn (Grant $grant) => $grant->scope === AccessScope::Team ? 'team' : "{$grant->scope->value}:{$grant->uuid}")->unique()->values()->all(),
                $group->first()->levels,
            ))
            ->values()
            ->all();
    }

    /**
     * @param  list<string>  $targets
     * @param  list<AccessLevel>  $levels
     * @return array{key: string, targets: list<string>, levels: array<string, bool>}
     */
    private function row(array $targets, array $levels): array
    {
        return [
            'key' => Str::random(10),
            'targets' => $targets,
            'levels' => collect(AccessLevel::cases())->mapWithKeys(fn (AccessLevel $level) => [$level->name => in_array($level, $levels, true)])->all(),
        ];
    }
}
