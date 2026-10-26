<?php

use App\Enums\ApplicationRecommendation;
use App\Livewire\Project\Application\Recommendations;
use App\Models\Application;
use App\Models\EnvironmentVariable;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::unguarded(fn () => InstanceSettings::updateOrCreate(['id' => 0], ['id' => 0]));

    $this->team = Team::factory()->create();
    $this->admin = User::factory()->create();
    $this->admin->teams()->attach($this->team, ['role' => 'admin']);
    $this->member = User::factory()->create();
    $this->member->teams()->attach($this->team, ['role' => 'member']);

    $this->server = Server::factory()->create(['team_id' => $this->team->id]);
    $this->destination = $this->server->standaloneDockers()->firstOrFail();
    $this->project = Project::factory()->create(['team_id' => $this->team->id]);

    $this->makeApplication = function (array $attributes = [], bool $useBuildSecrets = false, bool $withBuildVariable = true): Application {
        $application = Application::factory()->create([
            'environment_id' => $this->project->environments()->firstOrFail()->id,
            'destination_id' => $this->destination->id,
            'destination_type' => $this->destination->getMorphClass(),
            'build_pack' => 'dockerfile',
            ...$attributes,
        ]);
        $application->settings->update(['use_build_secrets' => $useBuildSecrets]);

        if ($withBuildVariable) {
            EnvironmentVariable::create([
                'key' => 'API_TOKEN',
                'value' => 'secret',
                'is_buildtime' => true,
                'is_runtime' => true,
                'resourceable_type' => Application::class,
                'resourceable_id' => $application->id,
            ]);
        }

        return $application->fresh();
    };
});

function openRecommendationValues(Application $application): array
{
    return ApplicationRecommendation::openFor($application->fresh())->map->value->all();
}

test('open recommendations depend on the build pack and the build secrets setting', function (array $attributes, bool $useBuildSecrets, array $expected) {
    $application = ($this->makeApplication)($attributes, $useBuildSecrets);

    expect(openRecommendationValues($application))->toBe($expected);
})->with([
    'dockerfile with build arguments' => [['build_pack' => 'dockerfile'], false, ['use-build-secrets']],
    'dockerfile with build secrets' => [['build_pack' => 'dockerfile'], true, []],
    'nixpacks with build arguments' => [['build_pack' => 'nixpacks'], false, ['use-build-secrets', 'use-railpack']],
    'compose with build arguments' => [['build_pack' => 'dockercompose'], false, ['use-build-secrets']],
    'railpack always uses secrets' => [['build_pack' => 'railpack'], false, []],
    'docker image does not build' => [['build_pack' => 'dockerimage'], false, []],
]);

test('build secrets are not recommended without user build-time variables', function () {
    $application = ($this->makeApplication)(['build_pack' => 'nixpacks'], withBuildVariable: false);

    // Nixpacks applications always get the NIXPACKS_NODE_VERSION control variable.
    expect($application->environment_variables()->where('key', 'NIXPACKS_NODE_VERSION')->exists())->toBeTrue()
        ->and(openRecommendationValues($application))->toBe(['use-railpack']);
});

test('build secrets are not recommended for raw compose deployments', function () {
    $application = ($this->makeApplication)(['build_pack' => 'dockercompose']);
    $application->settings->update(['is_raw_compose_deployment_enabled' => true]);

    expect(openRecommendationValues($application))->toBe([]);
});

test('the build secrets recommendation is stronger when the image is pushed to a registry', function () {
    $local = ($this->makeApplication)();
    $pushed = ($this->makeApplication)(['docker_registry_image_name' => 'ghcr.io/acme/app']);

    expect(ApplicationRecommendation::UseBuildSecrets->severity($local))->toBe('warning')
        ->and(ApplicationRecommendation::UseBuildSecrets->severity($pushed))->toBe('danger')
        ->and(ApplicationRecommendation::UseBuildSecrets->why($pushed))->toContain('ghcr.io/acme/app')
        ->and(ApplicationRecommendation::UseBuildSecrets->why($local))->not->toContain('pushes its image');
});

test('an admin turns on build secrets with one click', function () {
    $application = ($this->makeApplication)();
    $this->actingAs($this->admin);
    session(['currentTeam' => $this->team]);

    Livewire::test(Recommendations::class, ['application' => $application])
        ->call('applyFix', 'use-build-secrets')
        ->assertDispatched('success')
        ->assertDispatched('refresh')
        ->assertViewHas('openRecommendations', fn ($open) => $open->isEmpty());

    expect($application->fresh()->settings->use_build_secrets)->toBeTrue();
});

test('an admin switches a Nixpacks application to Railpack and keeps the read-only labels in sync', function () {
    $application = ($this->makeApplication)(['build_pack' => 'nixpacks'], useBuildSecrets: true);
    $application->settings->update(['is_container_label_readonly_enabled' => true]);
    $this->actingAs($this->admin);
    session(['currentTeam' => $this->team]);

    Livewire::test(Recommendations::class, ['application' => $application->fresh()])
        ->call('applyFix', 'use-railpack')
        ->assertDispatched('success');

    $application = $application->fresh();
    expect($application->build_pack)->toBe('railpack')
        ->and(base64_decode($application->custom_labels))->toBe(implode("\n", generateLabelsApplication($application)));
});

test('a dismissed recommendation leaves the open list and can be restored', function () {
    $application = ($this->makeApplication)();
    $this->actingAs($this->admin);
    session(['currentTeam' => $this->team]);

    $component = Livewire::test(Recommendations::class, ['application' => $application])
        ->call('dismiss', 'use-build-secrets')
        ->assertDispatched('refresh')
        ->assertViewHas('openRecommendations', fn ($open) => $open->isEmpty())
        ->assertViewHas('dismissedRecommendations', fn ($dismissed) => $dismissed->map->value->all() === ['use-build-secrets']);

    expect($application->fresh()->settings->dismissed_recommendations)->toBe(['use-build-secrets']);

    $component->call('restore', 'use-build-secrets')
        ->assertViewHas('openRecommendations', fn ($open) => $open->map->value->all() === ['use-build-secrets']);

    expect($application->fresh()->settings->dismissed_recommendations)->toBe([]);
});

test('unknown dismissed values do not hide recommendations', function () {
    $application = ($this->makeApplication)();
    $application->settings->update(['dismissed_recommendations' => ['removed-recommendation']]);

    expect(openRecommendationValues($application))->toBe(['use-build-secrets']);
});

test('a member cannot apply, dismiss, or restore recommendations', function (string $method) {
    $application = ($this->makeApplication)();
    $application->settings->update(['dismissed_recommendations' => $method === 'restore' ? ['use-build-secrets'] : null]);
    $this->actingAs($this->member);
    session(['currentTeam' => $this->team]);

    Livewire::test(Recommendations::class, ['application' => $application->fresh()])
        ->call($method, 'use-build-secrets')
        ->assertDispatched('error')
        ->assertNotDispatched('refresh');

    $settings = $application->fresh()->settings;
    expect($settings->use_build_secrets)->toBeFalse()
        ->and($settings->dismissed_recommendations)->toBe($method === 'restore' ? ['use-build-secrets'] : null);
})->with(['applyFix', 'dismiss', 'restore']);

test('the role in the application team decides after the user switches to a team they own', function () {
    $application = ($this->makeApplication)();
    $ownTeam = Team::factory()->create();
    $this->member->teams()->attach($ownTeam, ['role' => 'owner']);
    $this->actingAs($this->member);
    session(['currentTeam' => $this->team]);

    $component = Livewire::test(Recommendations::class, ['application' => $application]);
    session(['currentTeam' => $ownTeam]);
    $component->call('applyFix', 'use-build-secrets')->assertDispatched('error');

    expect($application->fresh()->settings->use_build_secrets)->toBeFalse();
});

test('an admin of another team cannot change the recommendations of an application', function () {
    $application = ($this->makeApplication)();
    $otherTeam = Team::factory()->create();
    $otherAdmin = User::factory()->create();
    $otherAdmin->teams()->attach($otherTeam, ['role' => 'owner']);
    $this->actingAs($otherAdmin);
    session(['currentTeam' => $otherTeam]);

    Livewire::test(Recommendations::class, ['application' => $application])
        ->call('applyFix', 'use-build-secrets')
        ->assertDispatched('error');

    expect($application->fresh()->settings->use_build_secrets)->toBeFalse();
});
