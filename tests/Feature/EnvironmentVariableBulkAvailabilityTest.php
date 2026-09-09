<?php

use App\Livewire\Project\Shared\EnvironmentVariable\All;
use App\Livewire\Project\Shared\EnvironmentVariable\Show;
use App\Models\Application;
use App\Models\Environment;
use App\Models\EnvironmentVariable;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function createBulkAvailabilityApplication(Team $team): Application
{
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);

    $application = Application::factory()->create([
        'environment_id' => $environment->id,
        'build_pack' => 'dockerfile',
    ]);

    EnvironmentVariable::query()
        ->where('resourceable_type', Application::class)
        ->where('resourceable_id', $application->id)
        ->delete();

    return $application;
}

function createBulkAvailabilityVariable(Application $application, string $key, array $attributes = []): EnvironmentVariable
{
    $variable = EnvironmentVariable::create(array_merge([
        'key' => $key,
        'value' => 'value',
        'is_preview' => false,
        'is_buildtime' => true,
        'is_runtime' => true,
        'resourceable_type' => Application::class,
        'resourceable_id' => $application->id,
    ], $attributes));

    EnvironmentVariable::query()
        ->where('resourceable_type', Application::class)
        ->where('resourceable_id', $application->id)
        ->where('is_preview', true)
        ->delete();

    return $variable->fresh();
}

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0]);

    $this->team = Team::factory()->create();

    $this->admin = User::factory()->create();
    $this->admin->teams()->attach($this->team, ['role' => 'admin']);

    $this->member = User::factory()->create();
    $this->member->teams()->attach($this->team, ['role' => 'member']);

    $this->application = createBulkAvailabilityApplication($this->team);

    $this->first = createBulkAvailabilityVariable($this->application, 'FIRST_VAR');
    $this->second = createBulkAvailabilityVariable($this->application, 'SECOND_VAR');
    $this->third = createBulkAvailabilityVariable($this->application, 'THIRD_VAR');

    $this->actingAs($this->admin);
    session(['currentTeam' => $this->team]);
});

it('turns build time availability off for the selected variables only', function () {
    Livewire::test(All::class, ['resource' => $this->application])
        ->call('loadEnvironmentVariables')
        ->call('bulkUpdateAvailability', 'is_buildtime', false, [$this->first->id, (string) $this->second->id])
        ->assertDispatched('success', 'Build time availability updated for 2 environment variables.')
        ->assertDispatched('refreshEnvs')
        ->assertDispatched('envsUpdated')
        ->assertDispatched('configurationChanged')
        ->assertDispatched(All::SELECTION_RESET_EVENT);

    expect($this->first->fresh()->is_buildtime)->toBeFalse()
        ->and($this->second->fresh()->is_buildtime)->toBeFalse()
        ->and($this->third->fresh()->is_buildtime)->toBeTrue()
        ->and($this->first->fresh()->is_runtime)->toBeTrue();
});

it('turns runtime availability on for selected variables that were switched off', function () {
    $this->first->update(['is_runtime' => false]);
    $this->second->update(['is_runtime' => false]);

    Livewire::test(All::class, ['resource' => $this->application])
        ->call('loadEnvironmentVariables')
        ->call('bulkUpdateAvailability', 'is_runtime', true, [$this->first->id, $this->third->id])
        ->assertDispatched('success', 'Runtime availability updated for 1 environment variable.');

    expect($this->first->fresh()->is_runtime)->toBeTrue()
        ->and($this->second->fresh()->is_runtime)->toBeFalse()
        ->and($this->third->fresh()->is_runtime)->toBeTrue();
});

it('reports when the selected variables already have the requested availability', function () {
    Livewire::test(All::class, ['resource' => $this->application])
        ->call('loadEnvironmentVariables')
        ->call('bulkUpdateAvailability', 'is_buildtime', true, [$this->first->id])
        ->assertDispatched('info')
        ->assertNotDispatched('success')
        ->assertNotDispatched('configurationChanged');
});

it('ignores variables that belong to another resource', function () {
    $otherTeam = Team::factory()->create();
    $otherApplication = createBulkAvailabilityApplication($otherTeam);
    $foreign = createBulkAvailabilityVariable($otherApplication, 'FOREIGN_VAR');

    Livewire::test(All::class, ['resource' => $this->application])
        ->call('loadEnvironmentVariables')
        ->call('bulkUpdateAvailability', 'is_buildtime', false, [$foreign->id, $this->first->id])
        ->assertDispatched('success', 'Build time availability updated for 1 environment variable.');

    expect($foreign->fresh()->is_buildtime)->toBeTrue()
        ->and($this->first->fresh()->is_buildtime)->toBeFalse();
});

it('skips generated service variables', function () {
    $generated = createBulkAvailabilityVariable($this->application, 'SERVICE_FQDN_APP');

    Livewire::test(All::class, ['resource' => $this->application])
        ->call('loadEnvironmentVariables')
        ->call('bulkUpdateAvailability', 'is_runtime', false, [$generated->id])
        ->assertDispatched('info');

    expect($generated->fresh()->is_runtime)->toBeTrue();
});

it('rejects flags other than build time and runtime', function () {
    Livewire::test(All::class, ['resource' => $this->application])
        ->call('loadEnvironmentVariables')
        ->call('bulkUpdateAvailability', 'is_literal', true, [$this->first->id])
        ->assertDispatched('error')
        ->assertNotDispatched('success');

    expect((bool) $this->first->fresh()->is_literal)->toBeFalse();
});

it('requires a selection', function () {
    Livewire::test(All::class, ['resource' => $this->application])
        ->call('loadEnvironmentVariables')
        ->call('bulkUpdateAvailability', 'is_buildtime', false, ['abc', null])
        ->assertDispatched('error')
        ->assertNotDispatched('success');

    expect($this->first->fresh()->is_buildtime)->toBeTrue();
});

it('does not let members change availability in bulk', function () {
    $this->actingAs($this->member);

    Livewire::test(All::class, ['resource' => $this->application])
        ->call('loadEnvironmentVariables')
        ->call('bulkUpdateAvailability', 'is_buildtime', false, [$this->first->id])
        ->assertDispatched('error')
        ->assertNotDispatched('success');

    expect($this->first->fresh()->is_buildtime)->toBeTrue();
});

it('does not refresh rows that the build time filter hides after the update', function () {
    Livewire::test(All::class, ['resource' => $this->application])
        ->call('loadEnvironmentVariables')
        ->call('toggleVariableFilter', 'buildtime')
        ->call('bulkUpdateAvailability', 'is_buildtime', false, [$this->first->id, $this->second->id])
        ->assertDispatched('success')
        ->assertNotDispatched('refreshEnvs');

    expect($this->first->fresh()->is_buildtime)->toBeFalse()
        ->and($this->second->fresh()->is_buildtime)->toBeFalse();
});

it('refreshes rows that stay visible under an active flag filter', function () {
    Livewire::test(All::class, ['resource' => $this->application])
        ->call('loadEnvironmentVariables')
        ->call('toggleVariableFilter', 'buildtime')
        ->call('bulkUpdateAvailability', 'is_runtime', false, [$this->first->id])
        ->assertDispatched('success')
        ->assertDispatched('refreshEnvs');
});

it('tells the browser to drop the selection whenever the row set changes', function () {
    $component = Livewire::test(All::class, ['resource' => $this->application])
        ->call('loadEnvironmentVariables');

    foreach ([
        ['toggleVariableFilter', ['runtime']],
        ['setTableSort', ['name_desc']],
        ['setEnvironmentVariablePage', [1]],
        ['clearFilters', []],
    ] as [$method, $arguments]) {
        $component->call($method, ...$arguments)->assertDispatched(All::SELECTION_RESET_EVENT);
    }

    $component->set('search', 'FIRST')->assertDispatched(All::SELECTION_RESET_EVENT);
});

it('renders a selection checkbox only for selectable rows that the user may edit', function () {
    $checkbox = 'data-env-select-id="'.$this->first->id.'"';

    Livewire::test(Show::class, ['env' => $this->first, 'type' => 'application', 'selectable' => true])
        ->assertSeeHtml($checkbox)
        ->assertSeeHtml('toggleSelected('.$this->first->id.')');

    Livewire::test(Show::class, ['env' => $this->first, 'type' => 'application'])
        ->assertDontSeeHtml($checkbox)
        ->assertDontSeeHtml('env-table-grid-selectable');

    $generated = createBulkAvailabilityVariable($this->application, 'SERVICE_URL_APP');

    Livewire::test(Show::class, ['env' => $generated, 'type' => 'application', 'selectable' => true])
        ->assertSeeHtml('env-table-grid-selectable')
        ->assertDontSeeHtml('data-env-select-id="'.$generated->id.'"');

    $this->actingAs($this->member);

    Livewire::test(Show::class, ['env' => $this->first, 'type' => 'application', 'selectable' => true])
        ->assertDontSeeHtml($checkbox);
});

it('wires the bulk availability controls into the environment variables table', function () {
    $all = file_get_contents(resource_path('views/livewire/project/shared/environment-variable/all.blade.php'));
    $show = file_get_contents(resource_path('views/livewire/project/shared/environment-variable/show.blade.php'));
    $hardcoded = file_get_contents(resource_path('views/livewire/project/shared/environment-variable/show-hardcoded.blade.php'));
    $checkbox = file_get_contents(resource_path('views/components/table/checkbox.blade.php'));
    $css = file_get_contents(resource_path('css/app.css'));

    expect($all)
        ->toContain('x-on:'.All::SELECTION_RESET_EVENT.'.window="selectedIds = []"')
        ->toContain('$canBulkEdit = auth()->user()?->can(\'manageEnvironment\', $resource) ?? false;')
        ->toContain('this.$wire.bulkUpdateAvailability(field, value, this.selectedIds)')
        ->toContain('<x-table.checkbox label="Select all environment variables on this page"')
        ->toContain('x-effect="$el.indeterminate = someSelected"')
        ->toContain('<span>Availability</span>')
        ->toContain("applyAvailability('is_buildtime', true)")
        ->toContain("applyAvailability('is_buildtime', false)")
        ->toContain("applyAvailability('is_runtime', true)")
        ->toContain("applyAvailability('is_runtime', false)")
        ->toContain('x-bind:disabled="selectedIds.length === 0"')
        ->toContain(':selectable="$canBulkEdit"')
        ->toContain('nextEnvironmentVariablePage,bulkUpdateAvailability"');

    expect($show)
        ->toContain('$isSelectable = $selectable && $canUpdate && $showBuildtime && $showRuntime;')
        ->toContain('<x-table.checkbox label="Select {{ $env->key }}" data-env-select-id="{{ $env->id }}"')
        ->toContain("{{ \$selectable ? 'env-table-grid-selectable' : '' }}");

    expect($hardcoded)
        ->toContain("{{ \$selectable ? 'env-table-grid-selectable' : '' }}")
        ->toMatch('/@if \(\$selectable\)\s*<span><\/span>\s*@endif/');

    expect($checkbox)
        ->toContain('type="checkbox"')
        ->not->toContain('<label')
        ->toContain('peer-checked:bg-coollabs')
        ->toContain('dark:peer-checked:bg-warning')
        ->toContain('peer-indeterminate:opacity-100');

    expect($css)
        ->toContain('.env-table-grid.env-table-grid-selectable {')
        ->toContain('grid-template-columns: 1.125rem minmax(14rem, 2.5fr) 4.8rem 6rem 4rem 4.5rem 4.8rem 4.2rem 3rem;')
        ->toContain('grid-template-columns: 1.125rem minmax(14rem, 2.5fr) 4.8rem 4rem 4.5rem 4.8rem 4.2rem 3rem;')
        ->toContain('.data-table-row.env-table-grid.env-table-grid-selectable > :nth-child(2)')
        ->toMatch('/@media \(max-width: 768px\) \{[\s\S]*?\.environment-table-scroll \.env-table-grid\.env-table-grid-selectable \{\s*min-width: 0;/');
});
