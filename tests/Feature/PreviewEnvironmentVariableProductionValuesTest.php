<?php

use App\Livewire\Project\Application\Previews;
use App\Livewire\Project\Shared\EnvironmentVariable\All;
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

beforeEach(function () {
    $this->withoutVite();
    config(['app.maintenance.driver' => 'file']);
    InstanceSettings::forceCreate(['id' => 0]);

    $this->user = User::factory()->create();
    $this->team = Team::factory()->create();
    $this->team->members()->attach($this->user, ['role' => 'owner']);
    $this->project = Project::factory()->create(['team_id' => $this->team->id]);
    $this->environment = Environment::factory()->create(['project_id' => $this->project->id]);
    $this->application = Application::factory()->create([
        'environment_id' => $this->environment->id,
        'build_pack' => 'dockerfile',
        'git_repository' => 'coollabsio/coolify-examples',
    ]);

    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);
});

function createProductionValueTestVariable(Application $application, string $key, ?string $value, array $attributes = []): EnvironmentVariable
{
    return EnvironmentVariable::create([
        'key' => $key,
        'value' => $value,
        'is_preview' => false,
        'resourceable_type' => Application::class,
        'resourceable_id' => $application->id,
        ...$attributes,
    ]);
}

function previewProductionValueTestVariable(Application $application, string $key): EnvironmentVariable
{
    return $application->environment_variables_preview()->where('key', $key)->sole();
}

it('finds preview variables that still have the production value', function () {
    createProductionValueTestVariable($this->application, 'SAME', 'production-value');
    createProductionValueTestVariable($this->application, 'CHANGED', 'production-value');
    createProductionValueTestVariable($this->application, 'EMPTY', '');
    createProductionValueTestVariable($this->application, 'LOCKED', 'locked-secret', ['is_shown_once' => true]);
    previewProductionValueTestVariable($this->application, 'CHANGED')->update(['value' => 'preview-value']);
    previewProductionValueTestVariable($this->application, 'LOCKED')->update(['is_shown_once' => true]);

    $ids = $this->application->previewEnvironmentVariableIdsWithProductionValues();

    expect($ids)->toEqualCanonicalizing([
        previewProductionValueTestVariable($this->application, 'SAME')->id,
        previewProductionValueTestVariable($this->application, 'LOCKED')->id,
    ]);
});

it('does not count an empty preview value as the production value', function () {
    createProductionValueTestVariable($this->application, 'API_KEY', 'production-value');
    previewProductionValueTestVariable($this->application, 'API_KEY')->update(['value' => null]);

    expect($this->application->previewEnvironmentVariableIdsWithProductionValues())->toBe([]);
});

it('copies the production value to a new preview variable by default', function () {
    expect($this->application->settings->use_production_values_in_previews)->toBeTrue();

    createProductionValueTestVariable($this->application, 'API_KEY', 'production-value', ['is_required' => true, 'comment' => 'Needed']);

    $preview = previewProductionValueTestVariable($this->application, 'API_KEY');

    expect($preview->value)->toBe('production-value')
        ->and((bool) $preview->is_required)->toBeTrue()
        ->and($preview->comment)->toBe('Needed');
});

it('creates an empty preview copy when production values in previews are off', function () {
    createProductionValueTestVariable($this->application, 'OLD_KEY', 'old-production-value');
    $this->application->settings->update(['use_production_values_in_previews' => false]);

    createProductionValueTestVariable($this->application->fresh(), 'API_KEY', 'production-value', ['is_required' => true, 'is_buildtime' => false]);

    $preview = previewProductionValueTestVariable($this->application, 'API_KEY');

    expect($preview->value)->toBe('')
        ->and((bool) $preview->is_required)->toBeTrue()
        ->and($preview->is_buildtime)->toBeFalse()
        ->and(previewProductionValueTestVariable($this->application, 'OLD_KEY')->value)->toBe('old-production-value');
});

it('marks preview rows that have the production value without sending values to the browser', function () {
    createProductionValueTestVariable($this->application, 'SAME', 'plain-production-value');
    createProductionValueTestVariable($this->application, 'CHANGED', 'production-value');
    createProductionValueTestVariable($this->application, 'LOCKED', 'locked-production-secret', ['is_shown_once' => true]);
    previewProductionValueTestVariable($this->application, 'CHANGED')->update(['value' => 'preview-value']);
    previewProductionValueTestVariable($this->application, 'LOCKED')->update(['is_shown_once' => true]);

    $component = Livewire::test(All::class, ['resource' => $this->application->fresh()])
        ->set('perPage', 50)
        ->call('loadEnvironmentVariables');

    $badges = $component->instance()->environmentVariablePageRows
        ->mapWithKeys(fn (array $row) => [$row['scope'].':'.$row['environmentVariable']->key => $row['sameAsProduction']]);

    expect($badges->all())->toMatchArray([
        'production:SAME' => false,
        'preview:SAME' => true,
        'preview:CHANGED' => false,
        'preview:LOCKED' => true,
    ]);
    $component->assertSee('Same as production')
        ->assertDontSee('locked-production-secret')
        ->assertDontSee('plain-production-value');
});

it('saves the production values in previews setting', function () {
    Livewire::test(Previews::class, ['application' => $this->application])
        ->assertSet('useProductionValuesInPreviews', true)
        ->set('useProductionValuesInPreviews', false)
        ->call('savePreviewSettings')
        ->assertHasNoErrors();

    expect($this->application->fresh()->settings->use_production_values_in_previews)->toBeFalse();
});

it('authorizes the production values in previews setting with the team of the application', function (string $role, bool $allowed) {
    $user = User::factory()->create();
    $this->team->members()->attach($user, ['role' => $role]);
    $otherTeam = Team::factory()->create();
    $otherTeam->members()->attach($user, ['role' => 'owner']);
    $this->actingAs($user);
    session(['currentTeam' => $this->team]);

    $component = Livewire::test(Previews::class, ['application' => $this->application]);
    session(['currentTeam' => $otherTeam]);

    $component->set('useProductionValuesInPreviews', false)
        ->call('savePreviewSettings')
        ->assertStatus($allowed ? 200 : 403);

    expect($this->application->fresh()->settings->use_production_values_in_previews)->toBe(! $allowed);
})->with([
    'member' => ['member', false],
    'admin' => ['admin', true],
]);

it('does not let an owner of another team change the setting', function () {
    $outsider = User::factory()->create();
    $otherTeam = Team::factory()->create();
    $otherTeam->members()->attach($outsider, ['role' => 'owner']);
    $this->actingAs($outsider);
    session(['currentTeam' => $otherTeam]);

    Livewire::test(Previews::class, ['application' => $this->application])
        ->set('useProductionValuesInPreviews', false)
        ->call('savePreviewSettings')
        ->assertForbidden();

    expect($this->application->fresh()->settings->use_production_values_in_previews)->toBeTrue();
});
