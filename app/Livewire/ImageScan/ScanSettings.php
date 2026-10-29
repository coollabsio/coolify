<?php

namespace App\Livewire\ImageScan;

use App\Enums\ImageScanGateMode;
use App\Enums\ImageScanOnError;
use App\Jobs\RefreshImageScanSummariesJob;
use App\Models\Application;
use App\Models\ImageScanIgnore;
use App\Models\ImageScanPolicy;
use App\Models\Server;
use App\Models\Service;
use App\Models\Team;
use App\Services\Access\AccessResolver;
use App\Services\ImageScan\ImageScanPolicyResolver;
use App\Services\ImageScan\ResourceScans;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Collection;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Scan rules of one level (instance, team, server or application), saved on change, and the ignores of that level.
 * A null value inherits from the level above. Databases and services have no rules of their own: their scope lists
 * only their ignores (a service with the ignores of its applications and databases). The scope is reloaded and
 * authorized on every request.
 */
class ScanSettings extends Component
{
    use AuthorizesRequests;

    private const SCOPES = ['instance', 'team', 'server', 'application', 'database', 'service'];

    /** Scopes that only list their ignores. */
    private const IGNORE_ONLY_SCOPES = ['database', 'service'];

    private const LEVEL_NAMES = ['instance' => 'Coolify', 'team' => 'team', 'server' => 'server'];

    #[Locked]
    public string $scopeType;

    /** Server, application, database, or service uuid, or the team id for the team scope (teams have no uuid). */
    #[Locked]
    public ?string $scopeUuid = null;

    public ?string $gateMode = '';

    public ?string $onScanError = '';

    public ?string $appScanEnabled = '1';

    public ?string $buildsByTeam = '';

    public ?string $scanNewServers = '0';

    public function mount(string $scopeType, ?string $scopeUuid = null): void
    {
        abort_unless(in_array($scopeType, self::SCOPES, true), 404);
        $this->scopeType = $scopeType;
        $this->scopeUuid = $scopeUuid;

        $scope = $this->loadScope();
        if ($this->isIgnoreOnly()) {
            return;
        }

        $policy = ImageScanPolicyResolver::forScope($scope);
        $this->gateMode = $policy->gate_mode?->value ?? '';
        $this->onScanError = $policy->on_scan_error?->value ?? '';

        if ($scope instanceof Application) {
            $this->appScanEnabled = self::boolToOption((bool) $scope->settings?->is_image_scan_enabled);
            $this->buildsByTeam = self::boolToOption($scope->settings?->image_builds_by_team);
        }
        if ($scope instanceof Team) {
            $this->scanNewServers = self::boolToOption((bool) $scope->scan_images_on_new_servers);
        }
    }

    public function updatedGateMode(): void
    {
        $this->saveRule('gate_mode', 'gateMode');
    }

    public function updatedOnScanError(): void
    {
        $this->saveRule('on_scan_error', 'onScanError');
    }

    public function updatedAppScanEnabled(): void
    {
        $application = $this->loadScope();
        abort_unless($application instanceof Application, 404);
        $this->authorizeEdit($application);
        $this->validate(['appScanEnabled' => ['required', 'in:1,0']]);

        $application->settings->is_image_scan_enabled = $this->appScanEnabled === '1';
        $application->settings->save();
        $this->audit($application, 'is_image_scan_enabled');
        $this->dispatch('success', 'Saved.');
    }

    public function updatedBuildsByTeam(): void
    {
        $application = $this->loadScope();
        abort_unless($application instanceof Application, 404);
        $this->authorizeEdit($application);
        $this->validate(['buildsByTeam' => ['nullable', 'in:1,0']]);

        $application->settings->image_builds_by_team = self::optionToBool($this->buildsByTeam);
        $application->settings->save();
        $this->audit($application, 'image_builds_by_team');
        $this->dispatch('success', 'Saved.');
    }

    public function updatedScanNewServers(): void
    {
        $team = $this->loadScope();
        abort_unless($team instanceof Team, 404);
        $this->authorizeEdit($team);
        $this->validate(['scanNewServers' => ['required', 'in:1,0']]);

        $team->scan_images_on_new_servers = $this->scanNewServers === '1';
        $team->save();
        $this->audit($team, 'scan_images_on_new_servers');
        $this->dispatch('success', 'Saved.');
    }

    /**
     * Removes an ignore of this level. On a resource page only the ignores of that resource (for a service: of its
     * applications and databases); team-wide ignores are removed on the team settings.
     */
    public function removeIgnore(string $uuid): void
    {
        $scope = $this->loadScope();
        $ignore = match (true) {
            $scope instanceof Team => ImageScanIgnore::query()
                ->where('team_id', $scope->id)
                ->whereNull('resource_id'),
            $this->isResourceScope($scope) => $this->resourceIgnores($scope),
            default => null,
        };
        $ignore = $ignore?->where('uuid', $uuid)->first();
        abort_unless($ignore instanceof ImageScanIgnore, 404);
        $this->authorizeEdit($scope);

        $ignore->delete();
        if ($ignore->resource_id === null) {
            RefreshImageScanSummariesJob::dispatch((int) $ignore->team_id);
        } elseif ($this->isResourceScope($scope)) {
            ResourceScans::refreshSummaries(ResourceScans::current($scope));
        }
        auditLog('ui.image_scan.ignore_removed', [
            'team_id' => $ignore->team_id,
            'scope' => $this->scopeType,
            'scope_uuid' => $this->scopeUuid,
            'vulnerability_id' => $ignore->vulnerability_id,
            'package' => $ignore->package,
        ]);
        $this->dispatch('success', 'Removed.');
    }

    private function saveRule(string $column, string $property): void
    {
        abort_if($this->isIgnoreOnly(), 404);
        $scope = $this->loadScope();
        $this->authorizeEdit($scope);

        $rules = ImageScanPolicy::validationRules()[$column];
        if ($scope === null) {
            $rules = ['required', ...array_filter($rules, fn ($rule) => $rule !== 'nullable')];
        }
        $this->validate([$property => $rules]);

        $policy = ImageScanPolicyResolver::forScope($scope);
        if ($column === 'gate_mode' && $scope instanceof Application && ! $scope->settings?->is_image_scan_enabled) {
            $this->gateMode = $policy->gate_mode?->value ?? '';
            $this->dispatch('error', 'Turn on the scan for this app first.');

            return;
        }

        $value = $this->{$property} === '' || $this->{$property} === null ? null : $this->{$property};
        $policy->{$column} = $value;
        $policy->save();
        $this->audit($scope, $column);
        $this->dispatch('success', 'Saved.');
    }

    /**
     * Loads the scope from the locked identifiers and checks that the user may see it.
     */
    private function loadScope(): ?Model
    {
        $user = auth()->user();
        abort_unless($user !== null, 403);

        if ($this->scopeType === 'instance') {
            abort_unless(isInstanceAdmin(), 403);

            return null;
        }

        $scope = match ($this->scopeType) {
            'team' => ctype_digit((string) $this->scopeUuid) ? Team::query()->find((int) $this->scopeUuid) : null,
            'server' => Server::query()->where('uuid', (string) $this->scopeUuid)->first(),
            'application' => Application::query()->where('uuid', (string) $this->scopeUuid)->first(),
            // Found only within the user's teams.
            'database', 'service' => ResourceScans::find($this->scopeType, (string) $this->scopeUuid, $user->teams->pluck('id')->all()),
        };
        $teamId = $this->teamIdOf($scope);
        abort_unless($teamId !== null && $user->teams->contains('id', $teamId), 404);

        if ($this->isResourceScope($scope)) {
            $this->authorize('view', $scope);
        }

        return $scope;
    }

    private function isIgnoreOnly(): bool
    {
        return in_array($this->scopeType, self::IGNORE_ONLY_SCOPES, true);
    }

    /**
     * An application, database, or service: a resource with its own Security page.
     */
    private function isResourceScope(?Model $scope): bool
    {
        return $scope instanceof Application || ($scope !== null && in_array($this->scopeType, self::IGNORE_ONLY_SCOPES, true));
    }

    private function teamIdOf(?Model $scope): ?int
    {
        return match (true) {
            $scope instanceof Team => $scope->id,
            $scope instanceof Server => $scope->team_id,
            $scope !== null && method_exists($scope, 'team') => $scope->team()?->id,
            default => null,
        };
    }

    /**
     * The ignores of a resource: of an application or database itself, of the applications and databases of a service
     * (the Security page sets them there), and of the service.
     *
     * @return Builder<ImageScanIgnore>
     */
    private function resourceIgnores(Model $scope): Builder
    {
        $owners = $scope instanceof Service ? ResourceScans::owners($scope)->push($scope) : collect([$scope]);

        return ImageScanIgnore::query()
            ->where('team_id', $this->teamIdOf($scope))
            ->where(fn (Builder $query) => $owners->each(fn (Model $owner) => $query->orWhere(fn (Builder $query) => $query
                ->where('resource_type', $owner->getMorphClass())
                ->where('resource_id', $owner->getKey()))));
    }

    private function authorizeEdit(?Model $scope): void
    {
        match (true) {
            $scope === null => abort_unless(isInstanceAdmin(), 403),
            $scope instanceof Team => abort_unless(auth()->user()->isAdminOfTeam($scope->id), 403),
            default => $this->authorize('update', $scope),
        };
    }

    private function canEdit(?Model $scope): bool
    {
        return match (true) {
            $scope === null => isInstanceAdmin(),
            $scope instanceof Team => auth()->user()->isAdminOfTeam($scope->id),
            default => auth()->user()->can('update', $scope),
        };
    }

    private function audit(?Model $scope, string $field): void
    {
        auditLog('ui.image_scan.settings_updated', [
            'team_id' => $this->teamIdOf($scope),
            'scope' => $this->scopeType,
            'scope_uuid' => $this->scopeUuid,
            'changed_fields' => [$field],
        ]);
    }

    private static function boolToOption(?bool $value): string
    {
        return match ($value) {
            true => '1',
            false => '0',
            null => '',
        };
    }

    private static function optionToBool(?string $value): ?bool
    {
        return match ($value) {
            '1' => true,
            '0' => false,
            default => null,
        };
    }

    /**
     * @return array<string, string>|null Inherit option label per rule field, null for the instance level.
     */
    private function inheritLabels(?Model $scope): ?array
    {
        if ($scope === null || $this->isIgnoreOnly()) {
            return null;
        }

        $inherited = ImageScanPolicyResolver::inheritedFor($scope);
        $label = fn (string $field, string $valueLabel) => 'Use '.self::LEVEL_NAMES[$inherited[$field]['source']].' default ('.$valueLabel.')';

        return [
            'gate_mode' => $label('gate_mode', $inherited['gate_mode']['value']->label()),
            'on_scan_error' => $label('on_scan_error', $inherited['on_scan_error']['value']->label()),
        ];
    }

    /**
     * @return Collection<int, ImageScanIgnore>
     */
    private function ignores(?Model $scope): Collection
    {
        return match (true) {
            $scope instanceof Team => ImageScanIgnore::query()
                ->where('team_id', $scope->id)
                ->whereNull('resource_id')
                ->latest()
                ->get(),
            $this->isResourceScope($scope) => ImageScanIgnore::query()
                ->where('team_id', $this->teamIdOf($scope))
                ->where(fn (Builder $query) => $query
                    ->whereNull('resource_id')
                    ->orWhereIn('id', $this->resourceIgnores($scope)->select('id')))
                ->orderByRaw('resource_id is null')
                ->latest()
                ->get(),
            default => collect(),
        };
    }

    /**
     * For a service: the name of the application or database each ignore was set for.
     *
     * @param  Collection<int, ImageScanIgnore>  $ignores
     * @return array<string, string> By ignore uuid.
     */
    private function ownerNames(?Model $scope, Collection $ignores): array
    {
        if (! $scope instanceof Service) {
            return [];
        }

        $names = ResourceScans::owners($scope)->mapWithKeys(fn (Model $owner): array => [
            $owner->getMorphClass().':'.$owner->getKey() => (string) ($owner->getAttribute('human_name') ?: $owner->getAttribute('name')),
        ]);

        return $ignores
            ->filter(fn (ImageScanIgnore $ignore): bool => $ignore->resource_id !== null && $names->has($ignore->resource_type.':'.$ignore->resource_id))
            ->mapWithKeys(fn (ImageScanIgnore $ignore): array => [(string) $ignore->uuid => $names->get($ignore->resource_type.':'.$ignore->resource_id)])
            ->all();
    }

    /**
     * The team settings page opens the session team, so the link is shown only when that is the team of the
     * resource and the user can open team-wide pages.
     */
    private function showsTeamSettingsLink(?Model $scope): bool
    {
        $teamId = $this->isResourceScope($scope) ? $this->teamIdOf($scope) : null;

        return $teamId !== null
            && currentTeam()?->id === $teamId
            && app(AccessResolver::class)->coversWholeTeam(auth()->user(), $teamId);
    }

    public function render(): View
    {
        $scope = $this->loadScope();
        $ignores = $this->ignores($scope);

        return view('livewire.image-scan.scan-settings', [
            'canEdit' => $this->canEdit($scope),
            'inheritLabels' => $this->inheritLabels($scope),
            'gateModeOptions' => collect(ImageScanGateMode::cases())
                ->map(fn (ImageScanGateMode $mode) => array_filter(['value' => $mode->value, 'label' => $mode->label(), 'description' => $mode->description()], fn ($value) => $value !== null))
                ->all(),
            'onScanErrorOptions' => collect(ImageScanOnError::cases())
                ->map(fn (ImageScanOnError $option) => ['value' => $option->value, 'label' => $option->label()])
                ->all(),
            'appScanOn' => $scope instanceof Application ? (bool) $scope->settings?->is_image_scan_enabled : true,
            'isDockerImage' => $scope instanceof Application && $scope->build_pack === 'dockerimage',
            'showsIgnores' => $scope instanceof Team || $this->isResourceScope($scope),
            'showsTeamSettingsLink' => $this->showsTeamSettingsLink($scope),
            'ignores' => $ignores,
            'ownerNames' => $this->ownerNames($scope, $ignores),
        ]);
    }
}
