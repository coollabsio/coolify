<?php

namespace App\Livewire\ImageScan;

use App\Enums\ImageScanAlertLevel;
use App\Enums\ImageScanOnError;
use App\Models\Application;
use App\Models\ImageScanIgnore;
use App\Models\ImageScanPolicy;
use App\Models\Server;
use App\Models\Team;
use App\Services\ImageScan\ImageScanPolicyResolver;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Collection;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Scan rules of one level (instance, team, server or application), saved on change.
 * A null value inherits from the level above. The scope is reloaded and authorized on every request.
 */
class ScanSettings extends Component
{
    use AuthorizesRequests;

    private const SCOPES = ['instance', 'team', 'server', 'application'];

    private const LEVEL_NAMES = ['instance' => 'Coolify', 'team' => 'team', 'server' => 'server'];

    #[Locked]
    public string $scopeType;

    /** Server or application uuid, or the team id for the team scope (teams have no uuid). */
    #[Locked]
    public ?string $scopeUuid = null;

    public ?string $alertLevel = '';

    public ?string $gateEnabled = '';

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
        $policy = ImageScanPolicyResolver::forScope($scope);
        $this->alertLevel = $policy->alert_level?->value ?? '';
        $this->gateEnabled = self::boolToOption($policy->gate_enabled);
        $this->onScanError = $policy->on_scan_error?->value ?? '';

        if ($scope instanceof Application) {
            $this->appScanEnabled = self::boolToOption((bool) $scope->settings?->is_image_scan_enabled);
            $this->buildsByTeam = self::boolToOption($scope->settings?->image_builds_by_team);
        }
        if ($scope instanceof Team) {
            $this->scanNewServers = self::boolToOption((bool) $scope->scan_images_on_new_servers);
        }
    }

    public function updatedAlertLevel(): void
    {
        $this->saveRule('alert_level', 'alertLevel');
    }

    public function updatedGateEnabled(): void
    {
        $this->saveRule('gate_enabled', 'gateEnabled');
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

    public function removeIgnore(string $uuid): void
    {
        $scope = $this->loadScope();
        $ignore = match (true) {
            $scope instanceof Team => ImageScanIgnore::query()
                ->where('team_id', $scope->id)
                ->whereNull('resource_id'),
            $scope instanceof Application => ImageScanIgnore::query()
                ->where('team_id', $scope->team()?->id)
                ->where('resource_type', $scope->getMorphClass())
                ->where('resource_id', $scope->id),
            default => null,
        };
        $ignore = $ignore?->where('uuid', $uuid)->first();
        abort_unless($ignore instanceof ImageScanIgnore, 404);
        $this->authorizeEdit($scope);

        $ignore->delete();
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
        $scope = $this->loadScope();
        $this->authorizeEdit($scope);

        $rules = ImageScanPolicy::validationRules()[$column];
        if ($scope === null) {
            $rules = ['required', ...array_filter($rules, fn ($rule) => $rule !== 'nullable')];
        }
        $this->validate([$property => $rules]);

        $policy = ImageScanPolicyResolver::forScope($scope);
        if ($column === 'gate_enabled' && $scope instanceof Application && ! $scope->settings?->is_image_scan_enabled) {
            $this->gateEnabled = self::boolToOption($policy->gate_enabled);
            $this->dispatch('error', 'Turn on the scan for this app first.');

            return;
        }

        $value = $this->{$property} === '' || $this->{$property} === null ? null : $this->{$property};
        $policy->{$column} = $column === 'gate_enabled' ? self::optionToBool($value) : $value;
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
        };
        $teamId = match (true) {
            $scope instanceof Team => $scope->id,
            $scope instanceof Server => $scope->team_id,
            $scope instanceof Application => $scope->team()?->id,
            default => null,
        };
        abort_unless($teamId !== null && $user->teams->contains('id', $teamId), 404);

        if ($scope instanceof Application) {
            $this->authorize('view', $scope);
        }

        return $scope;
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
            'team_id' => match (true) {
                $scope instanceof Team => $scope->id,
                $scope instanceof Server => $scope->team_id,
                $scope instanceof Application => $scope->team()?->id,
                default => null,
            },
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
        if ($scope === null) {
            return null;
        }

        $inherited = ImageScanPolicyResolver::inheritedFor($scope);
        $label = fn (string $field, string $valueLabel) => 'Use '.self::LEVEL_NAMES[$inherited[$field]['source']].' default ('.$valueLabel.')';

        return [
            'alert_level' => $label('alert_level', $inherited['alert_level']['value']->label()),
            'gate_enabled' => $label('gate_enabled', $inherited['gate_enabled']['value'] ? 'On' : 'Off'),
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
            $scope instanceof Application => ImageScanIgnore::query()
                ->where('team_id', $scope->team()?->id)
                ->where(fn (Builder $query) => $query
                    ->whereNull('resource_id')
                    ->orWhere(fn (Builder $query) => $query
                        ->where('resource_type', $scope->getMorphClass())
                        ->where('resource_id', $scope->id)))
                ->orderByRaw('resource_id is null')
                ->latest()
                ->get(),
            default => collect(),
        };
    }

    public function render(): View
    {
        $scope = $this->loadScope();

        return view('livewire.image-scan.scan-settings', [
            'canEdit' => $this->canEdit($scope),
            'inheritLabels' => $this->inheritLabels($scope),
            'alertLevelOptions' => collect(ImageScanAlertLevel::cases())
                ->map(fn (ImageScanAlertLevel $level) => ['value' => $level->value, 'label' => $level->label()])
                ->all(),
            'onScanErrorOptions' => collect(ImageScanOnError::cases())
                ->map(fn (ImageScanOnError $option) => ['value' => $option->value, 'label' => $option->label()])
                ->all(),
            'appScanOn' => $scope instanceof Application ? (bool) $scope->settings?->is_image_scan_enabled : true,
            'isDockerImage' => $scope instanceof Application && $scope->build_pack === 'dockerimage',
            'showsIgnores' => $scope instanceof Team || $scope instanceof Application,
            'ignores' => $this->ignores($scope),
        ]);
    }
}
